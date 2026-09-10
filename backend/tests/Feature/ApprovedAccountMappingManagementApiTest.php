<?php

namespace Tests\Feature;

use App\Models\AccountingPolicyVersion;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ApprovedAccountMappingManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $maker;

    private User $checker;

    private int $policyId;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['accounting.account-mappings.view', 'accounting.account-mappings.create', 'accounting.account-mappings.update', 'accounting.account-mappings.approve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->company = Company::create(['name' => 'Mapping API tenant', 'tax_code' => 'MAP-API']);
        $this->maker = User::factory()->create(['company_id' => $this->company->id]);
        $this->checker = User::factory()->create(['company_id' => $this->company->id]);
        $this->maker->givePermissionTo(['accounting.account-mappings.view', 'accounting.account-mappings.create', 'accounting.account-mappings.update', 'accounting.account-mappings.approve']);
        $this->checker->givePermissionTo(['accounting.account-mappings.view', 'accounting.account-mappings.approve']);
        $this->configureAccountingTenant($this->maker, $this->company);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Owner account', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        $this->policyId = $this->approvedPolicy();
    }

    public function test_api_creates_updates_and_approves_owner_supplied_mapping_with_audited_correlation(): void
    {
        Sanctum::actingAs($this->maker);
        $response = $this->withHeader('X-Request-ID', 'mapping-api-create-001')->postJson('/api/v1/approved-account-mappings', $this->payload());
        $response->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.account_code', '1111');
        $id = (int) $response->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'approved_account_mapping.draft_created', 'model_id' => $id, 'correlation_id' => 'mapping-api-create-001']);

        $this->withHeader('X-Request-ID', 'mapping-api-update-001')->putJson("/api/v1/approved-account-mappings/{$id}", ['mapping_context' => ['source' => 'api', 'method' => 'cash']])
            ->assertOk()->assertJsonPath('data.mapping_context.method', 'cash');
        $this->assertDatabaseHas('audit_logs', ['action' => 'approved_account_mapping.draft_updated', 'model_id' => $id, 'correlation_id' => 'mapping-api-update-001']);

        // Service-level maker/checker applies even though this actor owns approve permission.
        $this->postJson("/api/v1/approved-account-mappings/{$id}/approve")->assertForbidden();

        Sanctum::actingAs($this->checker);
        $this->withHeader('X-Request-ID', 'mapping-api-approve-001')->postJson("/api/v1/approved-account-mappings/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.is_immutable', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'approved_account_mapping.approved', 'model_id' => $id, 'correlation_id' => 'mapping-api-approve-001']);
        Sanctum::actingAs($this->maker);
        $this->putJson("/api/v1/approved-account-mappings/{$id}", ['account_code' => '9999'])->assertStatus(409);
    }

    public function test_api_enforces_rbac_tenant_scope_and_common_error_correlation_contract(): void
    {
        $unprivileged = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($unprivileged);
        $this->getJson('/api/v1/approved-account-mappings')->assertForbidden();

        Sanctum::actingAs($this->maker);
        $id = (int) $this->postJson('/api/v1/approved-account-mappings', $this->payload())->json('data.id');
        $other = Company::create(['name' => 'Other mapping tenant', 'tax_code' => 'MAP-API-OTHER']);
        $otherUser = User::factory()->create(['company_id' => $other->id]);
        $otherUser->givePermissionTo('accounting.account-mappings.view');
        Sanctum::actingAs($otherUser);
        $error = $this->withHeader('X-Request-ID', 'mapping-api-error-001')->getJson("/api/v1/approved-account-mappings/{$id}");
        $error->assertNotFound()->assertJsonStructure(['error', 'request_id']);
        $this->assertSame($error->json('request_id'), $error->headers->get('X-Request-ID'));
    }

    public function test_policy_picker_returns_only_approved_integrity_checked_policies_for_the_current_tenant(): void
    {
        Sanctum::actingAs($this->maker);

        $draft = app(AccountingPolicyLifecycleService::class)->createDraft($this->maker, [
            'company_id' => $this->company->id,
            'accounting_regime_profile_id' => FiscalYear::withoutGlobalScope('company')
                ->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail()
                ->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.inventory',
            'policy_version' => 'draft-only',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-supplied'],
            'required_dimensions' => [],
            'regulatory_dependencies' => [],
        ]);

        $response = $this->getJson('/api/v1/approved-account-mappings/policies')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->policyId)
            ->assertJsonPath('data.0.status', 'approved');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('draft', $draft->fresh()->status);

        $this->maker->revokePermissionTo('accounting.account-mappings.view');
        $this->getJson('/api/v1/approved-account-mappings/policies')->assertForbidden();
    }

    public function test_accountant_creates_period_close_policy_without_json_and_admin_approves_it(): void
    {
        foreach ([
            ['code' => '5119', 'name' => 'Revenue source', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '6429', 'name' => 'Expense source', 'type' => 'expense', 'nature' => 'debit'],
        ] as $account) {
            ChartOfAccount::create([
                ...$account,
                'company_id' => $this->company->id,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
        $profile = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('year', 2026)
            ->firstOrFail()
            ->accountingRegimeProfile()
            ->firstOrFail();
        Sanctum::actingAs($this->maker);

        $this->getJson('/api/v1/accounting-policies/profiles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $profile->id)
            ->assertJsonPath('data.0.fiscal_year', 2026);

        $created = $this->postJson('/api/v1/accounting-policies', [
            'accounting_regime_profile_id' => $profile->id,
            'policy_key' => 'posting.period_closing',
            'policy_version' => 'close-ui-v1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'source_accounts' => [
                ['account_code' => '5119', 'category' => 'revenue'],
                ['account_code' => '6429', 'category' => 'expense'],
            ],
            'regulatory_dependencies' => [],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.posting_rule_contract.schema', 'period-closing.v1')
            ->assertJsonPath('data.posting_rule_contract.source_accounts.0.account_code', '5119');
        $policyId = (int) $created->json('data.id');
        $this->assertDatabaseHas('accounting_policy_versions', [
            'id' => $policyId,
            'company_id' => $this->company->id,
            'created_by' => $this->maker->id,
            'status' => 'draft',
        ]);

        // Maker cannot self-approve even if a stale/custom permission grant
        // accidentally includes the approval permission.
        $this->postJson("/api/v1/accounting-policies/{$policyId}/approve")->assertForbidden();

        Sanctum::actingAs($this->checker);
        $this->postJson("/api/v1/accounting-policies/{$policyId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_immutable', true);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'accounting_policy.approved',
            'model_id' => $policyId,
        ]);

        Sanctum::actingAs($this->maker);
        $this->getJson('/api/v1/accounting-policies?status=approved')
            ->assertOk()
            ->assertJsonPath('data.0.id', $policyId);
    }

    public function test_period_close_policy_rejects_duplicate_inactive_parent_and_foreign_source_accounts(): void
    {
        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '5118',
            'name' => 'Inactive revenue',
            'type' => 'revenue',
            'nature' => 'credit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => false,
        ]);
        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '6428',
            'name' => 'Expense parent',
            'type' => 'expense',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => true,
            'is_active' => true,
        ]);
        $other = Company::create(['name' => 'Policy source tenant', 'tax_code' => 'POL-SOURCE-OTHER']);
        ChartOfAccount::create([
            'company_id' => $other->id,
            'code' => '5159',
            'name' => 'Foreign revenue',
            'type' => 'revenue',
            'nature' => 'credit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ]);

        $profile = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('year', 2026)
            ->firstOrFail()
            ->accountingRegimeProfile()
            ->firstOrFail();
        Sanctum::actingAs($this->maker);
        $base = [
            'accounting_regime_profile_id' => $profile->id,
            'policy_key' => 'posting.period_closing',
            'policy_version' => 'invalid-source-v1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'regulatory_dependencies' => [],
        ];
        $before = AccountingPolicyVersion::withoutGlobalScope('company')->count();

        foreach ([
            [['account_code' => '1111', 'category' => 'revenue'], ['account_code' => '1111', 'category' => 'expense']],
            [['account_code' => '5118', 'category' => 'revenue']],
            [['account_code' => '6428', 'category' => 'expense']],
            [['account_code' => '5159', 'category' => 'revenue']],
            [['account_code' => '1111', 'category' => 'revenue']],
        ] as $sourceAccounts) {
            $this->postJson('/api/v1/accounting-policies', [
                ...$base,
                'source_accounts' => $sourceAccounts,
            ])->assertUnprocessable();
        }

        $this->assertSame($before, AccountingPolicyVersion::withoutGlobalScope('company')->count());
    }

    public function test_mapping_management_api_rejects_a_policy_for_an_incompatible_posting_family(): void
    {
        Sanctum::actingAs($this->maker);

        $this->postJson('/api/v1/approved-account-mappings', [
            ...$this->payload(),
            'mapping_key' => 'period_close.result',
            'mapping_context' => [],
            'account_role' => 'result_clearing',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('accounting_policy_version_id');
    }

    public function test_mapping_management_api_rejects_a_matching_but_unapproved_policy(): void
    {
        $year = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('year', 2026)
            ->firstOrFail();
        $draft = app(AccountingPolicyLifecycleService::class)->createDraft($this->maker, [
            'company_id' => $this->company->id,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.cash_receipt',
            'policy_version' => 'unapproved-cash-v1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'draft-only'],
            'required_dimensions' => [],
            'regulatory_dependencies' => [],
        ]);

        Sanctum::actingAs($this->maker);
        $this->postJson('/api/v1/approved-account-mappings', [
            ...$this->payload(),
            'accounting_policy_version_id' => $draft->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('accounting_policy_version_id');
    }

    public function test_mapping_management_api_rejects_effective_dates_outside_the_selected_policy(): void
    {
        Sanctum::actingAs($this->maker);

        $this->postJson('/api/v1/approved-account-mappings', [
            ...$this->payload(),
            'effective_from' => '2025-12-31',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_accountant_updates_only_a_draft_policy_and_approved_policy_is_immutable(): void
    {
        $profile = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('year', 2026)
            ->firstOrFail()
            ->accountingRegimeProfile()
            ->firstOrFail();
        Sanctum::actingAs($this->maker);
        $created = $this->postJson('/api/v1/accounting-policies', [
            'accounting_regime_profile_id' => $profile->id,
            'policy_key' => 'posting.sales_invoice',
            'policy_version' => 'sales-ui-v1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'regulatory_dependencies' => [],
        ])->assertCreated();
        $id = (int) $created->json('data.id');

        $this->putJson("/api/v1/accounting-policies/{$id}", [
            'policy_version' => 'sales-ui-v2',
            'regulatory_dependencies' => ['Quy trình bán hàng nội bộ 2026'],
        ])->assertOk()
            ->assertJsonPath('data.policy_version', 'sales-ui-v2')
            ->assertJsonPath('data.regulatory_dependencies.0', 'Quy trình bán hàng nội bộ 2026');
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting_policy.draft_updated', 'model_id' => $id]);

        Sanctum::actingAs($this->checker);
        $this->checker->givePermissionTo('accounting.account-mappings.update');
        $this->putJson("/api/v1/accounting-policies/{$id}", [
            'policy_version' => 'checker-must-not-edit',
        ])->assertForbidden();
        $this->postJson("/api/v1/accounting-policies/{$id}/approve")->assertOk();
        Sanctum::actingAs($this->maker);
        $this->putJson("/api/v1/accounting-policies/{$id}", [
            'policy_version' => 'sales-ui-v3',
        ])->assertStatus(409);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['accounting_policy_version_id' => $this->policyId, 'mapping_key' => 'cash_bank.voucher', 'mapping_context' => ['source' => 'api'], 'account_role' => 'cash_debit', 'account_code' => '1111', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'regulatory_dependencies' => ['TT99/2025/TT-BTC']];
    }

    private function approvedPolicy(): int
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $service = app(AccountingPolicyLifecycleService::class);
        $draft = $service->createDraft($this->maker, ['company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id, 'policy_key' => 'posting.cash_receipt', 'policy_version' => 'map-api-v1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'posting_rule_contract' => ['mapping_reference' => 'owner-supplied'], 'required_dimensions' => [], 'regulatory_dependencies' => ['TT99/2025/TT-BTC']]);

        return $service->approve($this->maker, $draft)->id;
    }
}
