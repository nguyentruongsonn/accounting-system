<?php

namespace App\Services;

use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Model;

/**
 * Source-document period guard for the fixed-asset lifecycle.
 *
 * A fixed asset is both a long-lived register record and the source of a
 * capitalisation voucher.  This guard protects the accounting-facing
 * lifecycle only; it deliberately does not prescribe depreciation policy,
 * revaluation eligibility, or any accounting mapping.
 */
final class FixedAssetPeriodMutationGuard
{
    public function __construct(private readonly AccountingPeriodGuard $periodGuard)
    {
    }

    /** @param array<string, mixed> $data */
    public function assertAssetCreate(int $companyId, array $data): void
    {
        $this->periodGuard->assertOpen($companyId, $this->assetDate($data), 'lập chứng từ ghi tăng tài sản cố định');
    }

    /** @param array<string, mixed> $data */
    public function assertAssetUpdate(FixedAsset $asset, array $data): void
    {
        // Freeze the historical source first.  Otherwise a client could move
        // a closed-period asset to an open date and mutate it in one request.
        $this->assertExistingAsset($asset, 'sửa chứng từ ghi tăng tài sản cố định');

        if (array_key_exists('voucher_date', $data) || array_key_exists('purchase_date', $data)) {
            $candidate = array_merge([
                'voucher_date' => $asset->voucher_date,
                'purchase_date' => $asset->purchase_date,
            ], $data);

            $this->periodGuard->assertOpen((int) $asset->company_id, $this->assetDate($candidate), 'sửa chứng từ ghi tăng tài sản cố định');
        }
    }

    public function assertExistingAsset(FixedAsset $asset, string $operation): void
    {
        $this->periodGuard->assertOpen((int) $asset->company_id, $asset->voucher_date ?? $asset->purchase_date, $operation);
    }

    public function assertDuplicate(FixedAsset $asset): void
    {
        // Duplicate creates a distinct, draft source document dated today;
        // reading the original does not amend the historical voucher.
        $this->periodGuard->assertOpen((int) $asset->company_id, now()->toDateString(), 'nhân bản chứng từ ghi tăng tài sản cố định');
    }

    public function assertDepreciationMonth(int $companyId, string $month): void
    {
        $this->periodGuard->assertOpen($companyId, $month.'-01', "trích khấu hao tháng {$month}");
    }

    public function assertExistingDocument(Model $document, string $operation): void
    {
        $this->periodGuard->assertOpen(
            (int) $document->company_id,
            $document->accounting_date ?? $document->voucher_date,
            $operation,
        );
    }

    /** @param array<string, mixed> $data */
    public function assertNewEvent(FixedAsset $asset, array $data, string $operation): void
    {
        // Disposal/revaluation are new accounting events.  The acquisition
        // date of an old asset must not prevent a valid current-period event.
        $this->periodGuard->assertOpen((int) $asset->company_id, $data['voucher_date'] ?? now()->toDateString(), $operation);
    }

    /** @param array<string, mixed> $data */
    private function assetDate(array $data): mixed
    {
        return $data['voucher_date'] ?? $data['purchase_date'] ?? now()->toDateString();
    }
}
