<?php

namespace Database\Seeders;

use App\Models\AccountingPolicyVersion;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingRegimeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DefaultAccountingPolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $regimeService = app(AccountingRegimeService::class);
        $companies = Company::all();

        $voucherPolicyKeys = [
            'posting.purchase_invoice',
            'posting.sales_invoice',
            'posting.cash_receipt',
            'posting.cash_payment',
            'posting.bank_receipt',
            'posting.bank_payment',
            'posting.inventory_receipt',
            'posting.inventory_issue',
            'posting.purchase_return',
            'posting.purchase_discount',
            'posting.sales_return',
            'posting.sales_discount',
        ];

        foreach ($companies as $company) {
            $admin = User::withoutGlobalScope('company')
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->first();
            $adminId = $admin?->id ?? 1;

            $fiscalYears = FiscalYear::withoutGlobalScope('company')
                ->where('company_id', $company->id)
                ->get();

            foreach ($fiscalYears as $fiscalYear) {
                $profile = $regimeService->ensureProfile($fiscalYear);
                $year = $fiscalYear->year;
                $effectiveFrom = "{$year}-01-01";
                $effectiveTo = "{$year}-12-31";

                foreach ($voucherPolicyKeys as $policyKey) {
                    $policyVersion = "{$year}.1";
                    $postingRuleContract = ['mapping_reference' => 'approved-default-v1'];
                    $regulatoryDependencies = ['TT200/2014/TT-BTC'];
                    $requiredDimensions = [];

                    $canonical = [
                        'policy_key' => $policyKey,
                        'policy_version' => $policyVersion,
                        'accounting_regime_profile_id' => (int) $profile->id,
                        'effective_from' => $effectiveFrom,
                        'effective_to' => $effectiveTo,
                        'posting_rule_contract' => $postingRuleContract,
                        'required_dimensions' => $requiredDimensions,
                        'dimension_requirements' => [],
                        'regulatory_dependencies' => $regulatoryDependencies,
                    ];
                    $contractHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

                    $policy = AccountingPolicyVersion::withoutGlobalScope('company')->firstOrNew([
                        'company_id' => $company->id,
                        'policy_key' => $policyKey,
                        'accounting_regime_profile_id' => $profile->id,
                        'policy_version' => $policyVersion,
                    ]);

                    if (! $policy->exists) {
                        $policy->fill([
                            'company_id' => $company->id,
                            'created_by' => $adminId,
                            'accounting_regime_profile_id' => $profile->id,
                            'policy_key' => $policyKey,
                            'policy_version' => $policyVersion,
                            'status' => 'draft',
                            'effective_from' => $effectiveFrom,
                            'effective_to' => $effectiveTo,
                            'posting_rule_contract' => $postingRuleContract,
                            'required_dimensions' => $requiredDimensions,
                            'regulatory_dependencies' => $regulatoryDependencies,
                        ]);
                        $policy->save();
                    }

                    $now = CarbonImmutable::now();
                    AccountingPolicyVersion::withoutGlobalScope('company')
                        ->whereKey($policy->id)
                        ->update([
                            'status' => 'approved',
                            'approved_by' => $adminId,
                            'approved_at' => $now,
                            'posting_rule_contract' => $postingRuleContract,
                            'required_dimensions' => $requiredDimensions,
                            'regulatory_dependencies' => $regulatoryDependencies,
                            'contract_hash' => $contractHash,
                            'updated_at' => $now,
                        ]);
                }
            }
        }
    }
}
