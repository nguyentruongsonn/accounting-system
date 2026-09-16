<?php

namespace App\Services;

use App\Models\VoucherReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Prevents a posted source document from being detached from posted
 * downstream documents which reference it (returns, discounts, payments,
 * adjustments, ...). The guard is intentionally tenant-aware and returns
 * actionable voucher identifiers so the UI can tell the operator what must
 * be reversed first.
 */
final class PostedDependentDocumentGuard
{
    public function assertNone(int $companyId, string $targetType, int $targetId): void
    {
        $references = VoucherReference::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->orderBy('id')
            ->get();

        $dependents = [];
        foreach ($references as $reference) {
            $source = $this->source($reference->source_type, (int) $reference->source_id, $companyId);
            if ($source === null || ! $this->isPosted($source)) {
                continue;
            }

            $dependents[] = $this->label($source);
        }

        if ($dependents === []) {
            return;
        }

        throw ValidationException::withMessages([
            'dependent_documents' => [
                'Không thể bỏ ghi sổ hoặc hủy chứng từ vì còn chứng từ phụ thuộc đã ghi sổ: '.implode(', ', $dependents).'. Hãy xử lý các chứng từ này trước.',
            ],
        ]);
    }

    private function source(?string $sourceType, int $sourceId, int $companyId): ?Model
    {
        if ($sourceType === null || $sourceType === '' || $sourceId < 1 || ! class_exists($sourceType)) {
            return null;
        }

        $prototype = new $sourceType;
        if (! $prototype instanceof Model || ! Schema::hasTable($prototype->getTable()) || ! Schema::hasColumn($prototype->getTable(), 'company_id')) {
            return null;
        }

        return $sourceType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereKey($sourceId)
            ->first();
    }

    private function isPosted(Model $source): bool
    {
        if ((bool) $source->getAttribute('is_posted')) {
            return true;
        }

        return strtolower(trim((string) $source->getAttribute('status'))) === 'posted';
    }

    private function label(Model $source): string
    {
        foreach (['voucher_number', 'invoice_number', 'order_number', 'contract_number', 'count_number', 'request_number'] as $field) {
            $value = trim((string) $source->getAttribute($field));
            if ($value !== '') {
                return $value;
            }
        }

        return class_basename($source).' #'.$source->getKey();
    }
}
