<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\BankReconciliationExceptionEvent;
use App\Models\BankReconciliationMatchEvent;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A deliberately non-posting bank reconciliation foundation.
 *
 * Import, match and exception rows are immutable evidence.  A later approved
 * workflow may consume confirmed matches, but this service never changes a
 * bank voucher, journal entry, cash balance or accounting period.
 */
final class BankStatementReconciliationService
{
    public const IMPORT_SCHEMA = 'bank-statement-normalized.v1';

    /** @param array<string, mixed> $input */
    public function import(User $actor, string $idempotencyKey, array $input): array
    {
        $companyId = $this->companyId($actor);
        $key = $this->idempotencyKey($idempotencyKey);
        $normalized = $this->normalizeImport($input);
        $requestHash = $this->hash(['schema' => self::IMPORT_SCHEMA, 'bank_account_id' => $normalized['bank_account_id'], 'statement_reference' => $normalized['statement_reference'], 'source_format' => $normalized['source_format'], 'lines' => $normalized['lines']]);

        $existing = BankStatementImport::withoutGlobalScope('company')->where('company_id', $companyId)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $this->replayOrConflict($existing, $requestHash);
        }

        try {
            return DB::transaction(function () use ($actor, $companyId, $key, $normalized, $requestHash): array {
                $existing = BankStatementImport::withoutGlobalScope('company')->where('company_id', $companyId)->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $requestHash);
                }
                // Resolve the bank account inside the actor tenant before
                // taking a row lock; do not load a foreign resource and only
                // reject it after the lock has been acquired. Preserve the
                // explicit cross-tenant exception contract with a non-locking
                // existence probe; the foreign row is never hydrated.
                if (BankAccount::withoutGlobalScope('company')
                    ->whereKey($normalized['bank_account_id'])
                    ->where('company_id', '!=', $companyId)
                    ->exists()) {
                    throw new AuthorizationException('Bank account is not in the actor tenant.');
                }
                $account = BankAccount::withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->findOrFail($normalized['bank_account_id']);
                if ((int) $account->company_id !== $companyId) {
                    throw new AuthorizationException('Bank account is not in the actor tenant.');
                }
                if (! $account->is_active) {
                    throw new InvalidArgumentException('Bank statement import requires an active bank account.');
                }
                if (strtoupper((string) $account->currency) !== $normalized['currency_code']) {
                    throw new InvalidArgumentException('Statement currency must equal the bank account currency.');
                }

                $import = BankStatementImport::withoutGlobalScope('company')->create([
                    'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'bank_account_id' => $account->id,
                    'source_format' => $normalized['source_format'], 'statement_reference' => $normalized['statement_reference'],
                    'content_hash' => $requestHash, 'idempotency_key' => $key, 'request_hash' => $requestHash,
                    'status' => 'accepted', 'line_count' => count($normalized['lines']),
                    'source_metadata' => $normalized['source_metadata'], 'imported_by' => $actor->id, 'imported_at' => now(),
                ]);
                foreach ($normalized['lines'] as $line) {
                    BankStatementLine::withoutGlobalScope('company')->create([
                        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'bank_account_id' => $account->id,
                        'bank_statement_import_id' => $import->id, ...$line,
                    ]);
                }
                app(AuditService::class)->record($import, 'bank_statement.imported', [], [
                    'statement_reference' => $import->statement_reference, 'line_count' => $import->line_count, 'content_hash' => $import->content_hash,
                ], metadata: ['schema' => self::IMPORT_SCHEMA, 'side_effects' => 'none']);

                return ['import' => $import->load('lines'), 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            $winner = BankStatementImport::withoutGlobalScope('company')->where('company_id', $companyId)->where('idempotency_key', $key)->first();
            if ($winner !== null) {
                return $this->replayOrConflict($winner, $requestHash);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    public function proposeMatch(User $actor, string $lineUuid, array $input): BankReconciliationMatchEvent
    {
        return DB::transaction(function () use ($actor, $lineUuid, $input): BankReconciliationMatchEvent {
            $companyId = $this->companyId($actor);
            $line = $this->line($companyId, $lineUuid, true);
            [$type, $candidate] = $this->candidate($companyId, $input['candidate_type'] ?? null, $input['candidate_id'] ?? null, true);
            $this->assertCandidateFitsLine($line, $type, $candidate, $input);
            $event = BankReconciliationMatchEvent::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'bank_statement_line_id' => $line->id,
                'candidate_type' => $type, 'candidate_id' => $candidate->id, 'decision' => 'proposed',
                'amount_raw' => $line->amount_raw, 'amount_scale' => $line->amount_scale,
                'reason' => $this->reason($input['reason'] ?? null, false), 'recorded_by' => $actor->id, 'recorded_at' => now(),
            ]);
            app(AuditService::class)->record($event, 'bank_reconciliation.match_proposed', [], ['line_uuid' => $line->uuid, 'candidate_type' => $type, 'candidate_id' => $candidate->id], metadata: ['side_effects' => 'none']);
            return $event;
        }, 3);
    }

    public function decideMatch(User $actor, int $proposedEventId, string $decision, string $reason): BankReconciliationMatchEvent
    {
        if (! in_array($decision, ['confirmed', 'rejected', 'reversed'], true)) {
            throw new InvalidArgumentException('Bank reconciliation decision is unsupported.');
        }
        return DB::transaction(function () use ($actor, $proposedEventId, $decision, $reason): BankReconciliationMatchEvent {
            $companyId = $this->companyId($actor);
            $proposed = BankReconciliationMatchEvent::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->findOrFail($proposedEventId);
            if (! in_array($proposed->decision, ['proposed', 'confirmed'], true)) {
                throw new ConflictHttpException('Only a proposed or confirmed match can receive this decision.');
            }
            if ($proposed->recorded_by === $actor->id) {
                throw new AuthorizationException('A different actor must confirm, reject, or reverse a bank match.');
            }
            if ($decision === 'confirmed') {
                $alreadyConfirmed = BankReconciliationMatchEvent::withoutGlobalScope('company')->where('company_id', $companyId)->where('bank_statement_line_id', $proposed->bank_statement_line_id)->where('decision', 'confirmed')->exists();
                if ($alreadyConfirmed) throw new ConflictHttpException('Statement line already has a confirmed match.');
            }
            return BankReconciliationMatchEvent::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'bank_statement_line_id' => $proposed->bank_statement_line_id,
                'supersedes_event_id' => $proposed->id, 'candidate_type' => $proposed->candidate_type, 'candidate_id' => $proposed->candidate_id,
                'decision' => $decision, 'amount_raw' => $proposed->amount_raw, 'amount_scale' => $proposed->amount_scale,
                'reason' => $this->reason($reason, true), 'recorded_by' => $actor->id, 'recorded_at' => now(),
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function recordException(User $actor, string $exceptionKey, string $eventType, array $input): BankReconciliationExceptionEvent
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{3,100}$/', $exceptionKey) || ! in_array($eventType, ['opened', 'resolved', 'waived', 'reopened'], true)) {
            throw new InvalidArgumentException('Bank reconciliation exception identity or lifecycle event is invalid.');
        }
        $companyId = $this->companyId($actor);
        $latest = BankReconciliationExceptionEvent::withoutGlobalScope('company')->where('company_id', $companyId)->where('exception_key', $exceptionKey)->latest('id')->first();
        if (($eventType === 'opened' && $latest !== null) || ($eventType !== 'opened' && $latest === null)) throw new ConflictHttpException('Exception lifecycle transition is invalid.');
        if ($latest !== null && $latest->recorded_by === $actor->id) throw new AuthorizationException('A different actor must resolve, waive, or reopen an exception.');
        $line = isset($input['line_uuid']) ? $this->line($companyId, (string) $input['line_uuid']) : null;
        return BankReconciliationExceptionEvent::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'bank_statement_line_id' => $line?->id,
            'exception_key' => $exceptionKey, 'exception_code' => $this->code($input['exception_code'] ?? $latest?->exception_code),
            'event_type' => $eventType, 'severity' => $this->severity($input['severity'] ?? $latest?->severity),
            'reason' => $this->reason($input['reason'] ?? null, $eventType !== 'opened'), 'evidence' => $input['evidence'] ?? null,
            'recorded_by' => $actor->id, 'recorded_at' => now(),
        ]);
    }

    private function companyId(User $actor): int { if ((int) $actor->company_id < 1) throw new AuthorizationException('An actor company is required.'); return (int) $actor->company_id; }
    private function idempotencyKey(string $key): string { $key = trim($key); if ($key === '' || mb_strlen($key) > 100) throw new InvalidArgumentException('A valid Idempotency-Key is required.'); return $key; }
    private function line(int $companyId, string $uuid, bool $lock = false): BankStatementLine { $q = BankStatementLine::withoutGlobalScope('company')->where('company_id', $companyId)->where('uuid', $uuid); if ($lock) $q->lockForUpdate(); return $q->firstOrFail(); }
    private function reason(mixed $value, bool $required): ?string { $value = is_string($value) ? trim($value) : null; if (($required && $value === '') || ($value !== null && mb_strlen($value) > 2000)) throw new InvalidArgumentException('A valid reconciliation reason is required.'); return $value === '' ? null : $value; }
    private function code(mixed $value): string { if (! is_string($value) || ! preg_match('/^[A-Z0-9_.-]{3,60}$/', $value)) throw new InvalidArgumentException('Exception code is invalid.'); return $value; }
    private function severity(mixed $value): string { if (! in_array($value, ['info', 'warning', 'blocking'], true)) throw new InvalidArgumentException('Exception severity is invalid.'); return $value; }

    /** @return array{0: string, 1: BankPayment|BankReceipt} */
    private function candidate(int $companyId, mixed $type, mixed $id, bool $lock): array
    {
        $class = match ($type) { 'bank_payment' => BankPayment::class, 'bank_receipt' => BankReceipt::class, default => throw new InvalidArgumentException('Only bank payment or bank receipt candidates are supported.') };
        if (! filter_var($id, FILTER_VALIDATE_INT) || (int) $id < 1) throw new InvalidArgumentException('Candidate identifier is invalid.');
        $q = $class::withoutGlobalScope('company')->where('company_id', $companyId); if ($lock) $q->lockForUpdate(); $candidate = $q->findOrFail((int) $id);
        if (! $candidate->is_posted) throw new InvalidArgumentException('Only posted bank vouchers can be reconciled.');
        return [$type, $candidate];
    }
    private function assertCandidateFitsLine(BankStatementLine $line, string $type, BankPayment|BankReceipt $candidate, array $input): void
    {
        if ((int) $candidate->bank_account_id !== (int) $line->bank_account_id || strtoupper((string) $candidate->currency) !== $line->currency_code || ($type === 'bank_payment' ? 'debit' : 'credit') !== $line->direction) throw new InvalidArgumentException('Candidate does not belong to the statement account, currency, or direction.');
        $candidateAmount = DecimalMoney::normalize($candidate->getRawOriginal('amount'));
        $lineAmount = $this->asTwoDecimal($line->amount_raw, (int) $line->amount_scale);
        if ($candidateAmount !== $lineAmount) throw new InvalidArgumentException('This foundation supports only exact full-amount matching.');
        if (array_key_exists('amount_raw', $input) || array_key_exists('amount_scale', $input)) throw new InvalidArgumentException('Match amount is derived from immutable statement evidence.');
    }
    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeImport(array $input): array
    {
        $accountId = $input['bank_account_id'] ?? null; if (! filter_var($accountId, FILTER_VALIDATE_INT) || (int) $accountId < 1) throw new InvalidArgumentException('Bank account is required.');
        $format = $this->short($input['source_format'] ?? null, 40, 'Source format'); $reference = $this->short($input['statement_reference'] ?? null, 120, 'Statement reference');
        $currency = strtoupper($this->short($input['currency_code'] ?? null, 3, 'Statement currency')); if (! preg_match('/^[A-Z]{3}$/', $currency)) throw new InvalidArgumentException('Statement currency is invalid.');
        if (! is_array($input['lines'] ?? null) || count($input['lines']) < 1 || count($input['lines']) > 10000) throw new InvalidArgumentException('Statement must contain 1 to 10,000 lines.');
        $refs = []; $lines = [];
        foreach (array_values($input['lines']) as $index => $source) {
            if (! is_array($source)) throw new InvalidArgumentException('Statement line is invalid.');
            $lineReference = $this->short($source['line_reference'] ?? null, 160, 'Statement line reference'); if (isset($refs[$lineReference])) throw new InvalidArgumentException('Statement line references must be unique within an import.'); $refs[$lineReference] = true;
            $scale = $this->scale($source['amount_scale'] ?? null); $amount = $this->amount($source['amount_raw'] ?? null, $scale); $direction = $source['direction'] ?? null; if (! in_array($direction, ['debit', 'credit'], true)) throw new InvalidArgumentException('Statement line direction is invalid.');
            $running = array_key_exists('running_balance_raw', $source) || array_key_exists('running_balance_scale', $source) ? ['running_balance_raw' => $this->balance($source['running_balance_raw'] ?? null, $this->scale($source['running_balance_scale'] ?? null)), 'running_balance_scale' => $this->scale($source['running_balance_scale'] ?? null)] : ['running_balance_raw' => null, 'running_balance_scale' => null];
            $line = ['line_number' => $index + 1, 'line_reference' => $lineReference, 'booked_on' => $this->date($source['booked_on'] ?? null), 'value_on' => isset($source['value_on']) ? $this->date($source['value_on']) : null, 'direction' => $direction, 'amount_raw' => $amount, 'amount_scale' => $scale, 'currency_code' => $currency, ...$running, 'bank_reference' => $this->nullableShort($source['bank_reference'] ?? null, 160), 'counterparty_name' => $this->nullableShort($source['counterparty_name'] ?? null, 255), 'counterparty_account' => $this->nullableShort($source['counterparty_account'] ?? null, 120), 'description' => $this->nullableText($source['description'] ?? null), 'source_payload' => $source];
            $line['normalized_hash'] = $this->hash($line); $lines[] = $line;
        }
        return ['bank_account_id' => (int) $accountId, 'source_format' => $format, 'statement_reference' => $reference, 'currency_code' => $currency, 'source_metadata' => is_array($input['source_metadata'] ?? null) ? $input['source_metadata'] : null, 'lines' => $lines];
    }
    private function short(mixed $value, int $max, string $field): string { $value = is_string($value) ? trim($value) : ''; if ($value === '' || mb_strlen($value) > $max) throw new InvalidArgumentException("{$field} is invalid."); return $value; }
    private function nullableShort(mixed $value, int $max): ?string { if ($value === null || $value === '') return null; return $this->short($value, $max, 'Statement line field'); }
    private function nullableText(mixed $value): ?string { if ($value === null || $value === '') return null; return $this->short($value, 5000, 'Statement line description'); }
    private function scale(mixed $value): int { if (! is_int($value) && ! ctype_digit((string) $value)) throw new InvalidArgumentException('Exact amount scale is invalid.'); $value = (int) $value; if ($value < 0 || $value > 2) throw new InvalidArgumentException('Exact amount scale is unsupported.'); return $value; }
    private function amount(mixed $value, int $scale): string { $amount = $this->signedAmount($value, $scale); if (DecimalMoney::compare($this->asTwoDecimal($amount, $scale), DecimalMoney::ZERO) <= 0) throw new InvalidArgumentException('Statement amount must be positive.'); return $amount; }
    private function signedAmount(mixed $value, int $scale): string { if (! is_string($value) && ! is_int($value)) throw new InvalidArgumentException('Exact amount is invalid.'); $value = trim((string) $value); if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) || strlen(explode('.', $value, 2)[1] ?? '') > $scale) throw new InvalidArgumentException('Exact amount is invalid.'); return $value; }
    private function balance(mixed $value, int $scale): string { if (! is_string($value) && ! is_int($value)) throw new InvalidArgumentException('Exact balance is invalid.'); $value = trim((string) $value); if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) || strlen(explode('.', ltrim($value, '-'), 2)[1] ?? '') > $scale) throw new InvalidArgumentException('Exact balance is invalid.'); return $value; }
    private function asTwoDecimal(string $amount, int $scale): string { $fraction = explode('.', $amount, 2)[1] ?? ''; if (strlen($fraction) > $scale) throw new InvalidArgumentException('Exact amount exceeds its declared scale.'); return DecimalMoney::normalize($amount); }
    private function date(mixed $value): string { try { return CarbonImmutable::parse((string) $value)->toDateString(); } catch (\Throwable) { throw new InvalidArgumentException('Statement date is invalid.'); } }
    /** @return array{import: BankStatementImport, replayed: bool} */
    private function replayOrConflict(BankStatementImport $import, string $hash): array { if (! hash_equals($import->request_hash, $hash)) throw new ConflictHttpException('The Idempotency-Key was already used for a different import.'); return ['import' => $import->loadMissing('lines'), 'replayed' => true]; }
    private function hash(array $payload): string { $this->sort($payload); return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private function sort(array &$value): void { foreach ($value as &$item) if (is_array($item)) $this->sort($item); unset($item); if (! array_is_list($value)) ksort($value); }
}
