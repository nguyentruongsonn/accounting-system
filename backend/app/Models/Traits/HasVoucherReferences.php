<?php

namespace App\Models\Traits;

use App\Models\VoucherReference;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasVoucherReferences
{
    /**
     * Danh sách các chứng từ mà chứng từ này tham chiếu tới
     */
    public function references(): MorphMany
    {
        return $this->morphMany(VoucherReference::class, 'source');
    }

    /**
     * Danh sách các chứng từ khác đang tham chiếu tới chứng từ này (Tra cứu ngược)
     */
    public function referencedBy(): MorphMany
    {
        return $this->morphMany(VoucherReference::class, 'target');
    }

    /**
     * Đồng bộ danh sách chứng từ tham chiếu vào bảng voucher_references
     */
    public function syncReferences(?array $referencedVouchers): void
    {
        $this->references()->delete();
        if (! empty($referencedVouchers) && is_array($referencedVouchers)) {
            foreach ($referencedVouchers as $ref) {
                $targetType = $ref['target_type'] ?? $ref['model'] ?? null;
                if ($targetType && ! str_contains($targetType, '\\')) {
                    $targetType = 'App\\Models\\'.$targetType;
                }
                $targetId = $ref['target_id'] ?? $ref['real_id'] ?? $ref['id'] ?? null;

                $this->references()->create([
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'target_voucher_type' => $ref['voucher_type'] ?? 'Chứng từ gốc',
                    'target_voucher_number' => $ref['voucher_number'] ?? '',
                    'target_voucher_date' => isset($ref['voucher_date']) ? substr($ref['voucher_date'], 0, 10) : null,
                    'target_total_amount' => $ref['total_amount'] ?? 0,
                    'description' => $ref['description'] ?? null,
                ]);
            }
        }
    }
}
