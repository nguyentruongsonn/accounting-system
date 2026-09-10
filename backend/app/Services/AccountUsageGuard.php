<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Validation\ValidationException;

/**
 * Serializes account consumers with chart deletion and transfer.
 *
 * Call only inside the transaction which persists the account references.
 * AccountService locks the same tenant chart rows before delete/transfer.
 */
final class AccountUsageGuard
{
    /** @param list<string> $codes */
    public function lockActiveLeafAccounts(int $companyId, array $codes): void
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $code): string => trim((string) $code),
            $codes
        ))));
        if ($codes === []) {
            return;
        }

        $validCodes = ChartOfAccount::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_parent', false)
            ->whereIn('code', $codes)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('code')
            ->all();
        $invalidCodes = array_values(array_diff($codes, $validCodes));

        if ($invalidCodes !== []) {
            throw ValidationException::withMessages([
                'lines' => 'Tài khoản không tồn tại, đã khóa hoặc không phải tài khoản chi tiết của doanh nghiệp: '
                    .implode(', ', $invalidCodes),
            ]);
        }
    }
}
