<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Keeps inventory receipt/issue source documents immutable once their
 * accounting period is closed. This runs at the source-service boundary so
 * the API, jobs, and internal callers cannot bypass it.
 */
final class InventoryVoucherPeriodMutationGuard
{
    public function __construct(private readonly AccountingPeriodGuard $periodGuard)
    {
    }

    public function assertCreate(int $companyId, array $data, string $operation): void
    {
        $this->periodGuard->assertOpen($companyId, $this->effectiveDate($data), $operation);
    }

    public function assertUpdate(Model $voucher, array $data, string $operation): void
    {
        // Guard the stored source date before considering a client supplied
        // replacement, otherwise a closed voucher could be moved then edited.
        $this->assertExisting($voucher, $operation);

        if (array_key_exists('posting_date', $data) || array_key_exists('voucher_date', $data)) {
            $this->periodGuard->assertOpen((int) $voucher->company_id, $this->effectiveDate($data, $voucher), $operation);
        }
    }

    public function assertExisting(Model $voucher, string $operation): void
    {
        $this->periodGuard->assertOpen(
            (int) $voucher->company_id,
            $voucher->posting_date ?? $voucher->voucher_date,
            $operation,
        );
    }

    public function assertDuplicate(Model $voucher, string $operation): void
    {
        // Duplicates are new draft vouchers dated today; the original need not
        // be mutable, but the new document's period must be open.
        $this->periodGuard->assertOpen((int) $voucher->company_id, now()->toDateString(), $operation);
    }

    private function effectiveDate(array $data, ?Model $existing = null): mixed
    {
        return $data['posting_date']
            ?? $data['voucher_date']
            ?? $existing?->posting_date
            ?? $existing?->voucher_date
            ?? now()->toDateString();
    }
}
