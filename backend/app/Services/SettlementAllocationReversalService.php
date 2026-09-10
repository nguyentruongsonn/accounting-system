<?php

namespace App\Services;

use App\Models\PurchaseDiscount;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesReturn;
use App\Models\SettlementAllocation;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Reverses a return/discount allocation via the exact opposite source journal. */
final class SettlementAllocationReversalService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SettlementAllocationService $allocations,
        private readonly SettlementAllocationCorrectionService $corrections,
    ) {}

    public function reverse(User $actor, int $allocationId, string $reason, string $postingDate): SettlementAllocation
    {
        $companyId = (int) $actor->company_id;
        if ($companyId < 1) {
            throw new AuthorizationException('An actor company is required.');
        }
        $sourceType = SettlementAllocation::withoutGlobalScope('company')
            ->where('company_id', $companyId)->whereKey($allocationId)->value('source_document_type');
        if (in_array($sourceType, ['cash_payment', 'bank_payment', 'cash_receipt', 'bank_receipt'], true)) {
            return $this->corrections->reverse($actor, $allocationId, $reason, $postingDate);
        }

        return DB::transaction(function () use ($actor, $companyId, $allocationId, $reason, $postingDate): SettlementAllocation {
            $allocation = SettlementAllocation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereKey($allocationId)
                ->where('status', 'posted')
                ->where('allocation_direction', 'reduction')
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($allocation->source_document_type, ['purchase_return', 'purchase_discount', 'sales_return', 'sales_discount'], true)) {
                throw new InvalidArgumentException('Only posted return or discount allocations use this journal-reversal workflow.');
            }
            if (SettlementAllocation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('reverses_allocation_id', $allocation->getKey())
                ->exists()) {
                throw new InvalidArgumentException('The settlement allocation has already been reversed.');
            }

            $source = $this->source($allocation->source_document_type, (int) $allocation->source_document_id, $companyId);
            $journalId = (int) $source->journal_entry_id;
            if ($journalId < 1) {
                throw new InvalidArgumentException('The original adjustment has no linked posted journal to reverse.');
            }
            $journal = $this->journals->reverse($journalId, $companyId, $reason, $postingDate);

            return $this->allocations->createPosted($actor, [
                'source_document_type' => 'journal_reversal',
                'source_document_id' => $journal->getKey(),
                'target_document_type' => $allocation->target_document_type,
                'target_document_id' => $allocation->target_document_id,
                'allocation_kind' => $allocation->allocation_kind,
                'allocation_direction' => 'reversal',
                'reverses_allocation_id' => $allocation->getKey(),
                'amount_raw' => DecimalMoney::normalize((string) $allocation->amount_raw),
                'amount_scale' => (int) $allocation->amount_scale,
                'currency_code' => $allocation->currency_code,
                'effective_date' => $postingDate,
            ]);
        });
    }

    private function source(string $type, int $id, int $companyId): Model
    {
        $model = match ($type) {
            'purchase_return' => PurchaseReturn::class,
            'purchase_discount' => PurchaseDiscount::class,
            'sales_return' => SalesReturn::class,
            'sales_discount' => SalesDiscount::class,
            default => throw new InvalidArgumentException('Unsupported adjustment source.'),
        };
        $source = $model::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($id);
        if ((int) $source->company_id !== $companyId || ! $source->is_posted) {
            throw new AuthorizationException('The original adjustment is not posted in the actor tenant.');
        }

        return $source;
    }
}
