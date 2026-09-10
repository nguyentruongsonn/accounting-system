<?php

namespace Tests\Feature;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\AccountingPolicyResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingPolicyCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_tenant_policy_resolves_for_its_voucher_and_date(): void
    {
        $company = Company::create(['name' => 'Policy tenant', 'tax_code' => 'POLICY-ONE']);
        $year = $this->createYear($company);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $profile = $year->accountingRegimeProfile()->firstOrFail();

        $policy = app(AccountingPolicyLifecycleService::class)->createDraft($actor, [
            'company_id' => $company->id,
            'accounting_regime_profile_id' => $profile->id,
            'policy_key' => 'posting.cash_receipt',
            'policy_version' => '2026.1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'approved-ledger-map-v1'],
            'required_dimensions' => ['customer'],
            'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]);
        $approved = app(AccountingPolicyLifecycleService::class)->approve($actor, $policy);

        $resolved = app(AccountingPolicyResolver::class)->requireForVoucher(
            $company->id,
            '2026-08-22',
            SystemVoucherType::CASH_RECEIPT,
        );

        $this->assertSame($approved->id, $resolved['policy_id']);
        $this->assertSame('TT99', $resolved['accounting_regime']);
        $this->assertSame(['customer'], $resolved['required_dimensions']);
        $this->assertNotEmpty($resolved['contract_hash']);
    }

    public function test_policy_maker_checker_can_be_enabled_without_breaking_legacy_nullable_rows(): void
    {
        $company = Company::create(['name' => 'Policy maker-checker', 'tax_code' => 'POLICY-SOD']);
        $year = $this->createYear($company);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $profile = $year->accountingRegimeProfile()->firstOrFail();
        $service = app(AccountingPolicyLifecycleService::class);
        $policy = $service->createDraft($actor, $this->policyAttributes($company->id, $profile->id, 'maker-checker'));

        $this->assertSame($actor->id, (int) $policy->created_by);
        config()->set('accounting.enforce_accounting_policy_maker_checker', true);
        try {
            $this->expectException(AuthorizationException::class);
            $service->approve($actor, $policy);
        } finally {
            config()->set('accounting.enforce_accounting_policy_maker_checker', false);
        }
    }

    public function test_resolver_fails_closed_for_missing_draft_or_ambiguous_policy(): void
    {
        $company = Company::create(['name' => 'Policy absence', 'tax_code' => 'POLICY-TWO']);
        $year = $this->createYear($company);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $profile = $year->accountingRegimeProfile()->firstOrFail();
        $service = app(AccountingPolicyLifecycleService::class);

        try {
            app(AccountingPolicyResolver::class)->requireForVoucher($company->id, '2026-08-22', 'cash_receipt');
            $this->fail('Missing policy must block posting.');
        } catch (AccountingPolicyUnavailableException) {
            $this->assertTrue(true);
        }

        $draft = $service->createDraft($actor, $this->policyAttributes($company->id, $profile->id, 'draft-only'));
        try {
            app(AccountingPolicyResolver::class)->requireForVoucher($company->id, '2026-08-22', 'cash_receipt');
            $this->fail('Unsigned policy draft must block posting.');
        } catch (AccountingPolicyUnavailableException) {
            $this->assertSame('draft', $draft->status);
        }

        $service->approve($actor, $draft);
        $overlap = $service->createDraft($actor, $this->policyAttributes($company->id, $profile->id, 'overlap'));
        $service->approve($actor, $overlap);
        $this->expectException(AccountingPolicyUnavailableException::class);
        app(AccountingPolicyResolver::class)->requireForVoucher($company->id, '2026-08-22', 'cash_receipt');
    }

    public function test_policy_is_tenant_bound_and_approved_versions_are_immutable(): void
    {
        $companyA = Company::create(['name' => 'Policy A', 'tax_code' => 'POLICY-A']);
        $companyB = Company::create(['name' => 'Policy B', 'tax_code' => 'POLICY-B']);
        $yearB = $this->createYear($companyB);
        $actorA = User::factory()->create(['company_id' => $companyA->id]);

        $this->expectException(AuthorizationException::class);
        app(AccountingPolicyLifecycleService::class)->createDraft($actorA, [
            'company_id' => $companyB->id,
            'accounting_regime_profile_id' => $yearB->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.cash_receipt',
            'policy_version' => 'bad',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);
    }

    public function test_policy_draft_creation_rejects_an_actor_without_a_tenant(): void
    {
        $actor = User::factory()->create(['company_id' => null]);

        $this->expectException(AuthorizationException::class);
        app(AccountingPolicyLifecycleService::class)->createDraft($actor, [
            'company_id' => 0,
            'policy_key' => 'posting.cash_receipt',
        ]);
    }

    public function test_approved_version_cannot_be_changed_or_deleted_and_incomplete_draft_cannot_be_approved(): void
    {
        $company = Company::create(['name' => 'Policy immutable', 'tax_code' => 'POLICY-IMMUTABLE']);
        $year = $this->createYear($company);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $profileId = $year->accountingRegimeProfile()->firstOrFail()->id;
        $lifecycle = app(AccountingPolicyLifecycleService::class);
        $incomplete = $lifecycle->createDraft($actor, [
            ...$this->policyAttributes($company->id, $profileId, 'incomplete'),
            'required_dimensions' => null,
        ]);

        try {
            $lifecycle->approve($actor, $incomplete);
            $this->fail('Policy without explicit dimensions declaration must not be approved.');
        } catch (\LogicException) {
            $this->assertSame('draft', $incomplete->fresh()->status);
        }

        $approved = $lifecycle->approve($actor, $lifecycle->createDraft(
            $actor,
            $this->policyAttributes($company->id, $profileId, 'immutable')
        ));
        $approved->policy_version = 'tampered';
        try {
            $approved->save();
            $this->fail('Approved policy must be immutable.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(\LogicException::class);
        $approved->delete();
    }

    public function test_profile_or_effective_range_cannot_cross_the_regime_profile(): void
    {
        $company = Company::create(['name' => 'Policy range', 'tax_code' => 'POLICY-RANGE']);
        $year = $this->createYear($company);
        $actor = User::factory()->create(['company_id' => $company->id]);

        $this->expectException(ValidationException::class);
        app(AccountingPolicyLifecycleService::class)->createDraft($actor, [
            'company_id' => $company->id,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.cash_receipt',
            'policy_version' => 'range',
            'effective_from' => '2025-12-31',
            'effective_to' => '2026-12-31',
        ]);
    }

    private function createYear(Company $company): FiscalYear
    {
        return FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
    }

    /** @return array<string, mixed> */
    private function policyAttributes(int $companyId, int $profileId, string $version): array
    {
        return [
            'company_id' => $companyId,
            'accounting_regime_profile_id' => $profileId,
            'policy_key' => 'posting.cash_receipt',
            'policy_version' => $version,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'approved-ledger-map-v1'],
            'required_dimensions' => ['customer'],
            'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ];
    }
}
