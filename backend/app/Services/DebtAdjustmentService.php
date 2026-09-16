<?php

namespace App\Services;

use App\Models\DebtAdjustment;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SettlementAllocation;
use App\Models\User;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Controlled source aggregate for AP/AR credit notes and write-offs.
 *
 * A posted adjustment creates a trusted balanced journal and exactly one
 * immutable allocation to its typed invoice. Corrections are new, opposite
 * adjustments; posted rows are never unposted or edited.
 */
final class DebtAdjustmentService
{
    use GuardsPostedSettlementAllocations;

    public function __construct(
        private readonly JournalEntryService $journalEntries,
        private readonly SettlementAllocationService $allocations,
        private readonly AuditService $auditService,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): DebtAdjustment
    {
        $companyId = $this->companyId($actor);

        return DB::transaction(function () use ($actor, $companyId, $data): DebtAdjustment {
            $ledger = $this->ledger($data['ledger'] ?? null);
            $voucherDate = $this->date($data['voucher_date'] ?? null);
            $accountingDate = $this->date($data['accounting_date'] ?? $data['voucher_date'] ?? null);
            $this->periodGuard->assertOpen($companyId, $accountingDate, 'lập chứng từ điều chỉnh công nợ');
            $reference = $this->reference($ledger, $data['reference_document_id'] ?? null, $companyId, false);
            $amount = $this->amount($data['amount'] ?? null);
            $this->assertControlAccount($ledger, (string) ($data['debit_account'] ?? ''), (string) ($data['credit_account'] ?? ''));

            $adjustment = DebtAdjustment::withoutGlobalScope('company')->create([
                'company_id' => $companyId,
                'ledger' => $ledger,
                'adjustment_kind' => $this->kind($data['adjustment_kind'] ?? null),
                'voucher_number' => $this->voucherNumber($data['voucher_number'] ?? null, $companyId, $ledger),
                'voucher_date' => $voucherDate,
                'accounting_date' => $accountingDate,
                'reference_document_type' => $this->referenceType($ledger),
                'reference_document_id' => $reference->getKey(),
                'amount' => $amount,
                'debit_account' => (string) $data['debit_account'],
                'credit_account' => (string) $data['credit_account'],
                'description' => $this->description($data['description'] ?? null),
                'status' => 'draft',
                'is_posted' => false,
                'created_by' => $actor->getKey(),
            ]);
            $this->auditService->record($adjustment, 'debt_adjustment.created', [], $adjustment->getAttributes(), null, [
                'domain' => 'debt_adjustment', 'operation' => 'created',
            ]);

            return $adjustment;
        });
    }

    public function post(User $actor, int $id): DebtAdjustment
    {
        $companyId = $this->companyId($actor);

        return DB::transaction(function () use ($actor, $companyId, $id): DebtAdjustment {
            $adjustment = DebtAdjustment::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);
            $this->assertOwnedDraft($adjustment, $companyId);
            $this->periodGuard->assertOpen(
                $companyId,
                $adjustment->accounting_date ?? $adjustment->voucher_date,
                'ghi sổ chứng từ điều chỉnh công nợ',
            );
            $reference = $this->reference($adjustment->ledger, $adjustment->reference_document_id, $companyId, true);

            $journal = $this->journalEntries->createPosted([
                'company_id' => $companyId,
                'voucher_type' => 'debt_adjustment',
                'voucher_number' => 'GL-'.$adjustment->voucher_number,
                'voucher_date' => $adjustment->voucher_date->toDateString(),
                'posting_date' => $adjustment->accounting_date->toDateString(),
                'description' => $adjustment->description ?? 'Điều chỉnh công nợ '.$adjustment->voucher_number,
                'source_document_type' => DebtAdjustment::class,
                'source_document_id' => $adjustment->getKey(),
                'lines' => [[
                    'debit_account' => $adjustment->debit_account,
                    'credit_account' => $adjustment->credit_account,
                    'amount' => (string) $adjustment->amount,
                    'description' => $adjustment->description ?? 'Điều chỉnh công nợ '.$adjustment->voucher_number,
                ]],
            ]);

            $adjustment->forceFill([
                'journal_entry_id' => $journal->getKey(),
                'status' => 'posted',
                'is_posted' => true,
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
            ])->save();

            $direction = $adjustment->reversal_of_id === null ? 'reduction' : 'reversal';
            $originalAllocation = $direction === 'reversal'
                ? SettlementAllocation::withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->where('source_document_type', $this->sourceType($adjustment->ledger))
                    ->where('source_document_id', $adjustment->reversal_of_id)
                    ->where('target_document_type', $adjustment->reference_document_type)
                    ->where('target_document_id', $reference->getKey())
                    ->where('allocation_kind', $adjustment->adjustment_kind)
                    ->where('allocation_direction', 'reduction')
                    ->where('status', 'posted')
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;

            $this->allocations->createPosted($actor, [
                'source_document_type' => $this->sourceType($adjustment->ledger),
                'source_document_id' => $adjustment->getKey(),
                'target_document_type' => $adjustment->reference_document_type,
                'target_document_id' => $reference->getKey(),
                'allocation_kind' => $adjustment->adjustment_kind,
                'allocation_direction' => $direction,
                'reverses_allocation_id' => $originalAllocation?->getKey(),
                'amount_raw' => DecimalMoney::normalize((string) $adjustment->amount),
                'amount_scale' => 2,
                'effective_date' => $adjustment->accounting_date->toDateString(),
            ]);
            $this->auditService->record($adjustment, 'debt_adjustment.posted', ['status' => 'draft'], $adjustment->getAttributes(), null, [
                'domain' => 'debt_adjustment', 'operation' => 'posted', 'allocation_direction' => $direction,
            ]);

            return $adjustment->fresh();
        });
    }

    public function reverse(User $actor, int $id, string $voucherNumber, string $accountingDate, ?string $description = null): DebtAdjustment
    {
        $companyId = $this->companyId($actor);

        return DB::transaction(function () use ($actor, $companyId, $id, $voucherNumber, $accountingDate, $description): DebtAdjustment {
            $original = DebtAdjustment::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);
            if ((int) $original->company_id !== $companyId || ! $original->is_posted || $original->status !== 'posted') {
                throw new AuthorizationException('The original debt adjustment is not posted in the actor tenant.');
            }
            if (DebtAdjustment::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('reversal_of_id', $original->getKey())
                ->exists()) {
                throw new InvalidArgumentException('A debt adjustment may only be reversed once.');
            }
            $reversalDate = $this->date($accountingDate);
            $this->periodGuard->assertOpen($companyId, $reversalDate, 'đảo chứng từ điều chỉnh công nợ');

            $reversal = DebtAdjustment::withoutGlobalScope('company')->create([
                'company_id' => $companyId,
                'ledger' => $original->ledger,
                'adjustment_kind' => $original->adjustment_kind,
                'voucher_number' => $this->voucherNumber($voucherNumber, $companyId, $original->ledger),
                'voucher_date' => $reversalDate,
                'accounting_date' => $reversalDate,
                'reference_document_type' => $original->reference_document_type,
                'reference_document_id' => $original->reference_document_id,
                'reversal_of_id' => $original->getKey(),
                'amount' => $original->amount,
                'debit_account' => $original->credit_account,
                'credit_account' => $original->debit_account,
                'description' => $description ?: 'Đảo điều chỉnh công nợ '.$original->voucher_number,
                'status' => 'draft',
                'is_posted' => false,
                'created_by' => $actor->getKey(),
            ]);

            return $this->post($actor, $reversal->getKey());
        });
    }

    private function assertOwnedDraft(DebtAdjustment $adjustment, int $companyId): void
    {
        if ((int) $adjustment->company_id !== $companyId || $adjustment->is_posted || $adjustment->status !== 'draft') {
            throw new AuthorizationException('The debt adjustment is not a postable draft in the actor tenant.');
        }
    }

    private function reference(string $ledger, mixed $id, int $companyId, bool $mustBePosted): Model
    {
        $model = $ledger === 'ap' ? PurchaseInvoice::class : SalesInvoice::class;
        $reference = $model::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->find($this->positiveId($id));
        if ($reference === null) {
            throw new AuthorizationException('The referenced open-item document is not in the actor tenant.');
        }
        if ($mustBePosted && ! $reference->is_posted) {
            throw new AuthorizationException('The referenced open-item document is not posted in the actor tenant.');
        }

        return $reference;
    }

    private function assertControlAccount(string $ledger, string $debitAccount, string $creditAccount): void
    {
        $valid = $ledger === 'ap' ? str_starts_with($debitAccount, '331') : str_starts_with($creditAccount, '131');
        if (! $valid) {
            throw ValidationException::withMessages([
                $ledger === 'ap' ? 'debit_account' : 'credit_account' => ['The AP/AR control-account side must reduce the referenced open item.'],
            ]);
        }
    }

    private function companyId(User $actor): int
    {
        if ((int) $actor->company_id < 1) {
            throw new AuthorizationException('An actor company is required.');
        }

        return (int) $actor->company_id;
    }

    private function ledger(mixed $value): string
    {
        if (! in_array($value, ['ap', 'ar'], true)) {
            throw new InvalidArgumentException('Debt adjustment ledger must be ap or ar.');
        }

        return $value;
    }

    private function kind(mixed $value): string
    {
        if (! in_array($value, ['credit_note', 'write_off'], true)) {
            throw new InvalidArgumentException('Debt adjustment kind must be credit_note or write_off.');
        }

        return $value;
    }

    private function referenceType(string $ledger): string
    {
        return $ledger === 'ap' ? 'purchase_invoice' : 'sales_invoice';
    }

    private function sourceType(string $ledger): string
    {
        return $ledger === 'ap' ? 'ap_debt_adjustment' : 'ar_debt_adjustment';
    }

    private function positiveId(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('A positive referenced document id is required.');
        }

        return (int) $value;
    }

    private function amount(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => ['Amount must be a positive exact decimal with at most two decimals.']]);
        }

        $normalized = DecimalMoney::normalize($value);
        if (DecimalMoney::compare($normalized, DecimalMoney::ZERO) <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be a positive exact decimal with at most two decimals.']]);
        }

        return $normalized;
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || strtotime($value) === false) {
            throw ValidationException::withMessages(['accounting_date' => ['A valid ISO accounting date is required.']]);
        }

        return $value;
    }

    private function voucherNumber(mixed $value, int $companyId, string $ledger): string
    {
        $number = is_string($value) ? trim($value) : '';
        if ($number === '') {
            $number = strtoupper($ledger).'-ADJ-'.str_pad((string) (DebtAdjustment::withoutGlobalScope('company')->where('company_id', $companyId)->count() + 1), 6, '0', STR_PAD_LEFT);
        }
        if (mb_strlen($number) > 80) {
            throw ValidationException::withMessages(['voucher_number' => ['Voucher number is too long.']]);
        }

        return $number;
    }

    private function description(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
