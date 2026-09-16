<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Protects the cash/bank source-document lifecycle from changing a closed
 * accounting period.  This is deliberately a source-layer guard: callers do
 * not get a different result merely because they reach a voucher through an
 * API controller, queue worker, or another accounting service.
 */
final class CashBankVoucherPeriodMutationGuard
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
        // Check the source date first. A client must not be able to move an
        // existing closed-period voucher into an open period and then edit it.
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
        // A duplicate is a new voucher dated today; the original is read-only.
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
