<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingAuditTrailExplorer;
use App\Services\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccountingAuditTrailExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorised_auditor_gets_tenant_scoped_sanitised_paginated_evidence_only(): void
    {
        Permission::findOrCreate('accounting.audit-trail.view', 'web');
        $company = Company::create(['name' => 'Audit A', 'tax_code' => 'AT-A']);
        $other = Company::create(['name' => 'Audit B', 'tax_code' => 'AT-B']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->givePermissionTo('accounting.audit-trail.view');
        $this->configureAccountingTenant($actor, $company);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'NCC-AT', 'name' => 'Supplier']);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'invoice_number' => 'AT-001', 'invoice_date' => '2026-08-22', 'total_amount' => 100]);
        $this->audit($company->id, $actor->id, PurchaseInvoice::class, $invoice->id, 'purchase_invoice.policy_applied', 'audit-correlation-1', ['accounting_policy' => ['id' => 5], 'api_key' => 'never-show', 'provider_payload' => 'never-show']);
        $this->audit($other->id, null, PurchaseInvoice::class, 999, 'purchase_invoice.policy_applied', 'audit-correlation-1', ['foreign' => true]);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/accounting-audit-trail?entity_type=purchase_invoice&entity_id='.$invoice->id.'&action=purchase_invoice.policy_applied&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.correlation_id', 'audit-correlation-1')
            ->assertJsonPath('data.0.metadata.accounting_policy.id', 5)->assertJsonMissing(['api_key' => 'never-show'])->assertJsonMissing(['foreign' => true]);
        $this->getJson('/api/v1/accounting-audit-trail/purchase_invoice/'.$invoice->id)
            ->assertOk()->assertJsonPath('read_only', true)->assertJsonPath('entity.id', (string) $invoice->id)
            ->assertJsonFragment(['correlation_id' => 'audit-correlation-1'])->assertJsonMissing(['provider_payload' => 'never-show']);
    }

    public function test_endpoint_requires_permission_and_never_discloses_another_tenant_entity(): void
    {
        Permission::findOrCreate('accounting.audit-trail.view', 'web');
        $company = Company::create(['name' => 'Audit Local', 'tax_code' => 'AT-L']);
        $foreign = Company::create(['name' => 'Audit Foreign', 'tax_code' => 'AT-F']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->configureAccountingTenant($user, $company);
        $supplier = Supplier::create(['company_id' => $foreign->id, 'code' => 'NCC-F', 'name' => 'Foreign']);
        $invoice = PurchaseInvoice::create(['company_id' => $foreign->id, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'invoice_number' => 'FOREIGN-1', 'invoice_date' => '2026-08-22', 'total_amount' => 1]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/accounting-audit-trail')->assertForbidden();
        $user->givePermissionTo('accounting.audit-trail.view');
        $this->getJson('/api/v1/accounting-audit-trail/purchase_invoice/'.$invoice->id)->assertNotFound();
        $this->getJson('/api/v1/accounting-audit-trail/unknown/1')->assertStatus(422);
    }

    public function test_direct_audit_trace_cannot_be_requested_for_a_foreign_company(): void
    {
        $company = Company::create(['name' => 'Audit actor', 'tax_code' => 'AT-ACTOR']);
        $foreign = Company::create(['name' => 'Audit foreign', 'tax_code' => 'AT-FOREIGN']);
        Sanctum::actingAs(User::factory()->create(['company_id' => $company->id]));

        try {
            app(AccountingAuditTrailExplorer::class)->trace($foreign->id, 'purchase_invoice', '999');
            $this->fail('Direct audit trace accepted a foreign company context.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }
    }

    public function test_audit_writer_rejects_a_foreign_model_for_an_authenticated_actor(): void
    {
        $company = Company::create(['name' => 'Audit writer actor', 'tax_code' => 'AT-WRITER']);
        $foreign = Company::create(['name' => 'Audit writer foreign', 'tax_code' => 'AT-WRITER-F']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $foreign->id, 'code' => 'NCC-WRITER-F', 'name' => 'Foreign supplier']);
        $invoice = PurchaseInvoice::create([
            'company_id' => $foreign->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'invoice_number' => 'WRITER-F-001',
            'invoice_date' => '2026-08-22',
            'total_amount' => 1,
        ]);
        Sanctum::actingAs($actor);

        $this->expectException(AuthorizationException::class);
        app(AuditService::class)->record($invoice, 'audit.foreign_model_rejected');
    }

    public function test_foreign_commercial_source_create_does_not_write_audit_for_another_tenant(): void
    {
        $company = Company::create(['name' => 'Audit observer actor', 'tax_code' => 'AT-OBS-ACTOR']);
        $foreign = Company::create(['name' => 'Audit observer foreign', 'tax_code' => 'AT-OBS-FOREIGN']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($actor);

        $supplier = Supplier::create(['company_id' => $foreign->id, 'code' => 'NCC-OBS-F', 'name' => 'Foreign observer supplier']);
        $invoice = PurchaseInvoice::create([
            'company_id' => $foreign->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'invoice_number' => 'OBS-F-001',
            'invoice_date' => '2026-08-22',
            'total_amount' => 1,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'company_id' => $foreign->id,
            'model_type' => $invoice->getMorphClass(),
            'model_id' => $invoice->id,
        ]);
    }

    private function audit(int $companyId, ?int $userId, string $modelType, int $modelId, string $action, string $correlation, array $metadata): void
    {
        AuditLog::withoutGlobalScope('company')->create(['company_id' => $companyId, 'user_id' => $userId, 'model_type' => $modelType, 'model_id' => $modelId, 'action' => $action, 'correlation_id' => $correlation, 'metadata' => $metadata]);
    }
}
