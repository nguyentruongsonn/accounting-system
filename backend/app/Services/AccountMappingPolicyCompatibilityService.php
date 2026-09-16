<?php

namespace App\Services;

use App\Models\AccountingPolicyVersion;
use Illuminate\Validation\ValidationException;

final class AccountMappingPolicyCompatibilityService
{
    /** @var array<string, list<string>> */
    private const POLICY_KEYS_BY_MAPPING = [
        'inventory.voucher' => ['posting.inventory_receipt', 'posting.inventory_issue'],
        'purchase.invoice' => ['posting.purchase_invoice'],
        'purchase.return' => ['posting.purchase_return'],
        'purchase.discount' => ['posting.purchase_discount'],
        'sales.invoice' => ['posting.sales_invoice'],
        'sales.return' => ['posting.sales_return'],
        'sales.discount' => ['posting.sales_discount'],
        'cash_bank.voucher' => ['posting.cash_receipt', 'posting.cash_payment', 'posting.bank_receipt', 'posting.bank_payment'],
        'period_close.result' => ['posting.period_closing'],
    ];

    public function assertCompatible(int $companyId, int $policyId, string $mappingKey): AccountingPolicyVersion
    {
        $policy = AccountingPolicyVersion::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->find($policyId);
        $allowed = self::POLICY_KEYS_BY_MAPPING[$mappingKey] ?? null;

        if ($policy === null || $allowed === null || ! in_array($policy->policy_key, $allowed, true)) {
            throw ValidationException::withMessages([
                'accounting_policy_version_id' => 'Chính sách hạch toán không phù hợp với loại mapping đã chọn.',
            ]);
        }

        if ($policy->status !== 'approved' || $policy->approved_at === null || $policy->contract_hash === null) {
            throw ValidationException::withMessages([
                'accounting_policy_version_id' => 'Mapping chỉ được liên kết với chính sách hạch toán đã được phê duyệt và kiểm tra toàn vẹn.',
            ]);
        }

        return $policy;
    }
}
