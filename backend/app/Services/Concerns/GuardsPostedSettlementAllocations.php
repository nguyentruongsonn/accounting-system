<?php

namespace App\Services\Concerns;

use App\Models\SettlementAllocation;
use Illuminate\Validation\ValidationException;

/** Prevents a posted source voucher from being detached from audit evidence. */
trait GuardsPostedSettlementAllocations
{
    protected function assertHasNoPostedSettlementAllocations(int $companyId, string $sourceType, int $sourceId): void
    {
        $hasPostedAllocations = SettlementAllocation::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId)
            ->where('status', 'posted')
            ->exists();

        if ($hasPostedAllocations) {
            throw ValidationException::withMessages([
                'settlement_allocations' => ['The posted voucher has canonical settlement allocations and must be reversed, not unposted or voided.'],
            ]);
        }
    }

    protected function assertHasNoPostedSettlementAllocationsForTarget(int $companyId, string $targetType, int $targetId): void
    {
        $hasPostedAllocations = SettlementAllocation::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('target_document_type', $targetType)
            ->where('target_document_id', $targetId)
            ->where('status', 'posted')
            ->exists();

        if ($hasPostedAllocations) {
            throw ValidationException::withMessages([
                'settlement_allocations' => ['The posted invoice has canonical settlement allocations and must be reversed, not unposted or voided.'],
            ]);
        }
    }
}
