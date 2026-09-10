<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Validation\ValidationException;

class AccountingPolicyContractService
{
    /**
     * @param  list<array{account_code:string,category:string}>  $sourceAccounts
     * @return array<string, mixed>
     */
    public function build(int $companyId, string $policyKey, array $sourceAccounts = []): array
    {
        if ($policyKey !== 'posting.period_closing') {
            return ['mapping_reference' => 'owner-approved-ui'];
        }

        $codes = collect($sourceAccounts)->pluck('account_code')->map(fn ($code): string => trim((string) $code));
        if ($codes->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'source_accounts' => 'Mỗi tài khoản nguồn kết chuyển chỉ được khai báo một lần.',
            ]);
        }

        $accounts = ChartOfAccount::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereIn('code', $codes->all())
            ->get()
            ->keyBy('code');

        $normalized = collect($sourceAccounts)->values()->map(function (array $source, int $index) use ($accounts): array {
            $code = trim((string) $source['account_code']);
            $account = $accounts->get($code);
            if ($account === null || ! $account->is_active || $account->is_parent) {
                throw ValidationException::withMessages([
                    "source_accounts.{$index}.account_code" => 'Tài khoản nguồn phải là tài khoản chi tiết đang sử dụng của doanh nghiệp hiện tại.',
                ]);
            }
            if ((string) $account->type !== (string) $source['category']) {
                throw ValidationException::withMessages([
                    "source_accounts.{$index}.category" => 'Nhóm kết chuyển phải khớp tính chất doanh thu hoặc chi phí của tài khoản đã chọn.',
                ]);
            }

            return ['account_code' => $code, 'category' => (string) $source['category']];
        })->all();

        return ['schema' => 'period-closing.v1', 'source_accounts' => $normalized];
    }

    public function assertValid(int $companyId, array $contract): void
    {
        if (($contract['schema'] ?? null) !== 'period-closing.v1') {
            return;
        }

        /** @var list<array{account_code:string,category:string}> $sources */
        $sources = is_array($contract['source_accounts'] ?? null) ? $contract['source_accounts'] : [];
        $this->build($companyId, 'posting.period_closing', $sources);
    }
}
