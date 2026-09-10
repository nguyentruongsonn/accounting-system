<?php

namespace Tests\Feature;

use App\Exceptions\AccountingDimensionUnavailableException;
use App\Models\AccountingDimensionDefinition;
use App\Models\AccountingDimensionValue;
use App\Models\AccountingPolicyVersion;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingDimensionRequirementService;
use App\Services\AccountingDimensionValidationService;
use App\Services\AccountingPolicyLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingDimensionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_required_dimension_requires_a_same_tenant_active_value(): void
    {
        [$company, $actor, $policy] = $this->policy('project');
        $definition = AccountingDimensionDefinition::create([
            'company_id' => $company->id, 'code' => 'project', 'name' => 'Dự án', 'status' => 'active',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
        ]);
        app(AccountingDimensionRequirementService::class)->replaceDraftRequirements($actor, $policy, [
            ['accounting_dimension_definition_id' => $definition->id],
        ]);
        $policy = app(AccountingPolicyLifecycleService::class)->approve($actor, $policy);

        $validator = app(AccountingDimensionValidationService::class);
        try {
            $validator->assertSatisfied($company->id, $policy->id, '2026-08-22', []);
            $this->fail('A required dimension without a value must block posting.');
        } catch (AccountingDimensionUnavailableException) {
            $this->assertTrue(true);
        }

        $value = AccountingDimensionValue::create([
            'company_id' => $company->id, 'accounting_dimension_definition_id' => $definition->id,
            'code' => 'PRJ-001', 'name' => 'Dự án thử nghiệm', 'status' => 'active',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
        ]);
        $validator->assertSatisfied($company->id, $policy->id, '2026-08-22', ['project' => $value->id]);
        $this->assertTrue(true);
    }

    public function test_policy_declaration_without_explicit_effective_mapping_fails_closed(): void
    {
        [$company, $actor, $policy] = $this->policy('cost_center');
        $policy = app(AccountingPolicyLifecycleService::class)->approve($actor, $policy);

        $this->expectException(AccountingDimensionUnavailableException::class);
        app(AccountingDimensionValidationService::class)->assertSatisfied($company->id, $policy->id, '2026-08-22', ['cost_center' => 1]);
    }

    public function test_definition_value_and_policy_requirement_cannot_cross_tenants_or_mutate_after_approval(): void
    {
        [$company, $actor, $policy] = $this->policy('department');
        $other = Company::create(['name' => 'Other dimension tenant', 'tax_code' => 'DIM-OTHER']);
        $definition = AccountingDimensionDefinition::create([
            'company_id' => $company->id, 'code' => 'department', 'name' => 'Phòng ban', 'status' => 'active',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
        ]);

        $this->expectException(AuthorizationException::class);
        app(AccountingDimensionRequirementService::class)->replaceDraftRequirements($actor, $policy, [
            ['accounting_dimension_definition_id' => $this->otherDefinition($other)->id],
        ]);
    }

    public function test_linked_requirements_must_match_policy_and_are_immutable_after_approval(): void
    {
        [$company, $actor, $policy] = $this->policy('department');
        $definition = AccountingDimensionDefinition::create([
            'company_id' => $company->id, 'code' => 'department', 'name' => 'Phòng ban', 'status' => 'active',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
        ]);
        $requirements = app(AccountingDimensionRequirementService::class)->replaceDraftRequirements($actor, $policy, [
            ['accounting_dimension_definition_id' => $definition->id],
        ]);
        $approved = app(AccountingPolicyLifecycleService::class)->approve($actor, $policy);

        $this->expectException(\LogicException::class);
        $requirements->sole()->delete();
        $this->assertSame('approved', $approved->status);
    }

    private function otherDefinition(Company $company): AccountingDimensionDefinition
    {
        return AccountingDimensionDefinition::create([
            'company_id' => $company->id, 'code' => 'department', 'name' => 'Other', 'status' => 'active',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
        ]);
    }

    /** @return array{Company, User, AccountingPolicyVersion} */
    private function policy(string $dimension): array
    {
        $company = Company::create(['name' => 'Dimension tenant '.$dimension, 'tax_code' => 'DIM-'.strtoupper($dimension)]);
        $year = FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
        ]);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $policy = app(AccountingPolicyLifecycleService::class)->createDraft($actor, [
            'company_id' => $company->id,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.cash_receipt', 'policy_version' => 'dimension-v1',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'tenant-approved-map'],
            'required_dimensions' => [$dimension], 'regulatory_dependencies' => [],
        ]);

        return [$company, $actor, $policy];
    }
}
