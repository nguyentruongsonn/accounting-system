<?php

namespace Tests\Feature;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Models\AccountingPolicyVersion;
use App\Models\ApprovedAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\ApprovedAccountMappingResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovedAccountMappingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_independently_approved_tenant_mapping_resolves_with_canonical_context(): void
    {
        [$company, $maker, $checker, $policy] = $this->context();
        $this->account($company, '1111');
        $mapping = app(ApprovedAccountMappingLifecycleService::class)->createDraft($maker, $this->attributes($policy, [
            'customer_type' => 'retail', 'channel' => 'bank',
        ]));
        $approved = app(ApprovedAccountMappingLifecycleService::class)->approve($checker, $mapping);

        $resolved = app(ApprovedAccountMappingResolver::class)->require(
            $company->id, $policy->id, '2026-08-22', 'cash.receipt',
            ['channel' => 'bank', 'customer_type' => 'retail'], ['cash_or_bank']
        );

        $this->assertSame($approved->id, $resolved['cash_or_bank']['mapping_id']);
        $this->assertSame('1111', $resolved['cash_or_bank']['account_code']);
        $this->assertNotEmpty($resolved['cash_or_bank']['contract_hash']);
    }

    public function test_missing_draft_ambiguous_or_inactive_mapping_fails_closed(): void
    {
        [$company, $maker, $checker, $policy] = $this->context('missing');
        $this->account($company, '1111');
        $service = app(ApprovedAccountMappingLifecycleService::class);
        $draft = $service->createDraft($maker, $this->attributes($policy));

        try {
            app(ApprovedAccountMappingResolver::class)->require($company->id, $policy->id, '2026-08-22', 'cash.receipt', [], ['cash_or_bank']);
            $this->fail('A draft mapping must not resolve.');
        } catch (AccountingAccountMappingUnavailableException) {
            $this->assertSame('draft', $draft->status);
        }

        $approved = $service->approve($checker, $draft);
        ChartOfAccount::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', '1111')->update(['is_active' => false]);
        $this->expectException(AccountingAccountMappingUnavailableException::class);
        app(ApprovedAccountMappingResolver::class)->require($company->id, $policy->id, '2026-08-22', 'cash.receipt', [], ['cash_or_bank']);
        $this->assertSame('approved', $approved->status);
    }

    public function test_maker_cannot_self_approve_and_approved_mapping_is_immutable(): void
    {
        [$company, $maker, $checker, $policy] = $this->context('sod');
        $this->account($company, '1111');
        $service = app(ApprovedAccountMappingLifecycleService::class);
        $mapping = $service->createDraft($maker, $this->attributes($policy));
        try {
            $service->approve($maker, $mapping);
            $this->fail('Maker/checker separation is required.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $mapping->fresh()->status);
        }
        $approved = $service->approve($checker, $mapping);
        $approved->account_code = '1121';
        $this->expectException(\LogicException::class);
        $approved->save();
    }

    public function test_overlap_and_cross_tenant_or_unapproved_policy_are_rejected(): void
    {
        [$company, $maker, $checker, $policy] = $this->context('overlap');
        $this->account($company, '1111');
        $this->account($company, '1121');
        $service = app(ApprovedAccountMappingLifecycleService::class);
        $service->approve($checker, $service->createDraft($maker, $this->attributes($policy)));
        $overlap = $service->createDraft($maker, $this->attributes($policy, [], '1121'));
        try {
            $service->approve($checker, $overlap);
            $this->fail('An overlapping approved role must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('draft', $overlap->fresh()->status);
        }

        $other = Company::create(['name' => 'Mapping other', 'tax_code' => 'MAP-OTHER']);
        $this->expectException(AuthorizationException::class);
        $service->createDraft($maker, [...$this->attributes($policy), 'company_id' => $other->id]);
    }

    /** @return array{Company,User,User,AccountingPolicyVersion} */
    private function context(string $suffix = 'one'): array
    {
        $company = Company::create(['name' => 'Mapping '.$suffix, 'tax_code' => 'MAP-'.strtoupper($suffix)]);
        $year = FiscalYear::withoutGlobalScope('company')->create(['company_id' => $company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $policy = app(AccountingPolicyLifecycleService::class)->createDraft($maker, [
            'company_id' => $company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.cash_receipt', 'policy_version' => 'map-'.$suffix,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-supplied'], 'required_dimensions' => [], 'regulatory_dependencies' => [],
        ]);
        return [$company, $maker, $checker, app(AccountingPolicyLifecycleService::class)->approve($maker, $policy)];
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function attributes(AccountingPolicyVersion $policy, array $context = [], string $code = '1111'): array
    {
        return [
            'company_id' => $policy->company_id, 'accounting_policy_version_id' => $policy->id,
            'mapping_key' => 'cash.receipt', 'mapping_context' => $context, 'account_role' => 'cash_or_bank',
            'account_code' => $code, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'regulatory_dependencies' => [],
        ];
    }

    private function account(Company $company, string $code): void
    {
        ChartOfAccount::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'code' => $code, 'name' => 'Owner supplied '.$code,
            'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false, 'is_active' => true,
        ]);
    }
}
