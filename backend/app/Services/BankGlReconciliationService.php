<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankGlReconciliationException;
use App\Models\BankGlReconciliationRun;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\BankReconciliationMatchEvent;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Captures evidence about whether a bank-statement-to-GL tie-out is possible.
 *
 * This is intentionally a fail-closed capability assessment, not a bank
 * balance calculation. Existing match events can be inspected for source →
 * posted-journal lineage only; they do not prove statement completeness,
 * opening/closing balances, an approved bank control-account mapping, or a
 * close-ready reconciliation.
 */
final class BankGlReconciliationService
{
    public const ALGORITHM_VERSION = 'bank-statement-gl-capability.v1';

    public function capture(User $actor, int $bankAccountId, string $asOfDate): BankGlReconciliationRun
    {
        $companyId = (int) $actor->company_id;
        if ($companyId < 1) throw new AuthorizationException('An actor company is required.');
        if ($bankAccountId < 1) throw new InvalidArgumentException('Bank account is invalid.');
        try { $cutoff = CarbonImmutable::parse($asOfDate)->toDateString(); } catch (\Throwable) { throw new InvalidArgumentException('As-of date is invalid.'); }

        $account = BankAccount::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($bankAccountId);
        $lineage = $this->confirmedMatchLineage($companyId, $account->id, $cutoff);
        $completeness = $this->sourceCompleteness($lineage);
        $missing = array_values(array_filter($completeness, static fn (array $item): bool => ! $item['available']));
        $snapshot = [
            'schema' => self::ALGORITHM_VERSION,
            'bank_account_id' => $account->id,
            'as_of_date' => $cutoff,
            'status' => 'not_available',
            'source_completeness' => $completeness,
            // Never substitute movement totals, voucher amounts or a zero
            // variance for a bank balance when the required contract is absent.
            'amounts' => null,
            'statement' => 'No bank or GL balance and no difference has been calculated by this foundation.',
        ];
        $contractHash = $this->hash([
            'schema' => self::ALGORITHM_VERSION,
            'required_contracts' => ['statement_provenance_and_coverage', 'approved_bank_gl_account_mapping', 'approved_balance_and_exception_policy'],
            'lineage_rules' => ['confirmed_event_current', 'posted_voucher', 'posted_journal', 'source_document_identity'],
        ]);

        return DB::transaction(function () use ($actor, $companyId, $account, $cutoff, $completeness, $missing, $snapshot, $contractHash): BankGlReconciliationRun {
            $run = BankGlReconciliationRun::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'bank_account_id' => $account->id,
                'as_of_date' => $cutoff, 'status' => 'not_available', 'algorithm_version' => self::ALGORITHM_VERSION,
                'contract_hash' => $contractHash, 'source_completeness' => $completeness,
                'divergence_count' => count($missing), 'snapshot' => $snapshot,
                'snapshot_hash' => $this->hash($snapshot), 'requested_by' => $actor->id, 'recorded_at' => now(),
            ]);
            foreach ($missing as $condition) {
                BankGlReconciliationException::withoutGlobalScope('company')->create([
                    'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'reconciliation_run_id' => $run->id,
                    'exception_code' => $condition['code'], 'severity' => 'blocking', 'reason' => $condition['reason'],
                    'evidence' => ['bank_account_id' => $account->id, 'as_of_date' => $cutoff, 'contract_hash' => $contractHash, 'observed' => $condition['observed'] ?? null],
                    'recorded_at' => now(),
                ]);
            }
            app(AuditService::class)->record($run, 'bank_gl_reconciliation.captured', [], [
                'bank_account_id' => $account->id, 'as_of_date' => $cutoff, 'status' => 'not_available',
                'blocking_conditions' => array_column($missing, 'code'),
            ], metadata: ['algorithm_version' => self::ALGORITHM_VERSION, 'side_effects' => 'none', 'amounts_calculated' => false]);
            return $run->load('exceptions');
        }, 3);
    }

    /** @return array<string,array{available:bool,code:string,reason:string,observed:array<string,mixed>}> */
    private function sourceCompleteness(array $lineage): array
    {
        $requiredSchema = Schema::hasTable('bank_statement_lines')
            && Schema::hasColumns('bank_statement_lines', ['company_id', 'bank_account_id', 'booked_on', 'amount_raw', 'amount_scale'])
            && Schema::hasTable('bank_reconciliation_match_events')
            && Schema::hasColumns('bank_reconciliation_match_events', ['company_id', 'bank_statement_line_id', 'candidate_type', 'candidate_id', 'decision', 'supersedes_event_id'])
            && Schema::hasTable('journal_entries')
            && Schema::hasColumns('journal_entries', ['company_id', 'id', 'status', 'source_document_type', 'source_document_id']);

        return [
            'statement_and_match_schema' => $this->condition($requiredSchema, 'bank_gl_source_schema_unavailable', 'The normalized statement, match-event, or journal source-lineage schema is unavailable.', ['schema_available' => $requiredSchema]),
            'confirmed_match_journal_lineage' => $this->condition($lineage['invalid_count'] === 0, 'bank_gl_confirmed_match_lineage_unproven', 'At least one current confirmed bank match lacks a provable posted voucher-to-posted-journal source lineage.', $lineage),
            'statement_provenance_and_cutoff_coverage' => $this->condition(false, 'bank_statement_coverage_not_owner_approved', 'No owner-approved statement provenance, opening/closing balance, overlap/gap, and cutoff-coverage contract exists for this bank account.', ['statement_lines_at_cutoff' => $lineage['statement_line_count']]),
            'bank_control_account_mapping' => $this->condition(false, 'bank_control_account_mapping_not_owner_approved', 'No effective-dated owner-approved mapping proves which GL account(s) and journal lines represent this bank account.', ['bank_account_id' => $lineage['bank_account_id']]),
            'balance_and_exception_policy' => $this->condition(false, 'bank_gl_balance_exception_policy_not_owner_approved', 'No owner-approved bank/GL balance, authorized exception, waiver, and sign-off policy exists; this run cannot assert a tie-out.', ['unmatched_or_unproven_statement_lines' => $lineage['unproven_statement_line_count']]),
        ];
    }

    /** @return array{bank_account_id:int,statement_line_count:int,current_confirmed_match_count:int,valid_count:int,invalid_count:int,unproven_statement_line_count:int,invalid_event_ids:list<int>} */
    private function confirmedMatchLineage(int $companyId, int $bankAccountId, string $cutoff): array
    {
        $lines = BankStatementLine::withoutGlobalScope('company')->where('company_id', $companyId)->where('bank_account_id', $bankAccountId)->whereDate('booked_on', '<=', $cutoff)->get(['id']);
        $lineIds = $lines->pluck('id')->all();
        if ($lineIds === []) return ['bank_account_id' => $bankAccountId, 'statement_line_count' => 0, 'current_confirmed_match_count' => 0, 'valid_count' => 0, 'invalid_count' => 0, 'unproven_statement_line_count' => 0, 'invalid_event_ids' => []];

        $events = BankReconciliationMatchEvent::withoutGlobalScope('company')
            ->where('company_id', $companyId)->whereIn('bank_statement_line_id', $lineIds)->where('decision', 'confirmed')
            ->whereNotExists(function ($query): void { $query->selectRaw('1')->from('bank_reconciliation_match_events as later')->whereColumn('later.supersedes_event_id', 'bank_reconciliation_match_events.id'); })
            ->get();
        $validLines = []; $invalid = [];
        foreach ($events as $event) {
            if ($this->hasProvableJournalLineage($companyId, $event)) $validLines[(int) $event->bank_statement_line_id] = true;
            else $invalid[] = (int) $event->id;
        }
        return ['bank_account_id' => $bankAccountId, 'statement_line_count' => count($lineIds), 'current_confirmed_match_count' => $events->count(), 'valid_count' => count($validLines), 'invalid_count' => count($invalid), 'unproven_statement_line_count' => count($lineIds) - count($validLines), 'invalid_event_ids' => $invalid];
    }

    private function hasProvableJournalLineage(int $companyId, BankReconciliationMatchEvent $event): bool
    {
        $class = match ($event->candidate_type) { 'bank_payment' => BankPayment::class, 'bank_receipt' => BankReceipt::class, default => null };
        if ($class === null) return false;
        $voucher = $class::withoutGlobalScope('company')->where('company_id', $companyId)->find($event->candidate_id);
        if ($voucher === null || ! $voucher->is_posted || ! $voucher->journal_entry_id) return false;
        return JournalEntry::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($voucher->journal_entry_id)
            ->where('status', 'posted')->where('source_document_type', $class)->where('source_document_id', $voucher->id)->exists();
    }

    /** @param array<string,mixed> $observed @return array{available:bool,code:string,reason:string,observed:array<string,mixed>} */
    private function condition(bool $available, string $code, string $reason, array $observed): array { return compact('available', 'code', 'reason', 'observed'); }
    private function hash(array $payload): string { $this->sort($payload); return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private function sort(array &$value): void { foreach ($value as &$item) if (is_array($item)) $this->sort($item); unset($item); if (! array_is_list($value)) ksort($value); }
}
