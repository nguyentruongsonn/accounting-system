<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EInvoiceDocument;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\EInvoiceLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class EInvoiceLifecycleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Some focused suites boot from the existing test schema snapshot.
        // Execute this isolated migration when that snapshot predates the
        // feature, so the lifecycle contract itself is exercised in CI.
        if (! Schema::hasTable('e_invoice_documents')) {
            (require database_path('migrations/2026_08_22_161181_create_einvoice_documents_table.php'))->up();
        }
    }

    public function test_records_append_only_lifecycle_with_hash_lineage_and_audit(): void
    {
        $company = Company::query()->firstOrFail();
        $maker = User::factory()->create(['company_id' => $company->id]);
        $issuer = User::factory()->create(['company_id' => $company->id]);
        $invoice = $this->invoice($company->id, $maker->id);
        $service = app(EInvoiceLifecycleService::class);
        $draft = $service->record($company->id, $maker->id, $this->payload($invoice->id, 'draft'));
        $issued = $service->record($company->id, $issuer->id, $this->payload($invoice->id, 'issued', $draft->id));

        $this->assertSame($draft->id, $issued->supersedes_einvoice_document_id);
        $this->assertSame(hash('sha256', '{"invoice":"SI-TAX"}'), $issued->payload_hash);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'einvoice.lifecycle_recorded', 'model_id' => $issued->id]);
        $this->expectException(LogicException::class);
        $issued->update(['document_reference' => 'tampered']);
    }

    public function test_maker_cannot_record_issued_event_and_cross_tenant_lineage_is_rejected(): void
    {
        $company = Company::query()->firstOrFail();
        $other = Company::create(['name' => 'Other', 'tax_code' => 'OTHER', 'address' => 'Other']);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $otherActor = User::factory()->create(['company_id' => $other->id]);
        $invoice = $this->invoice($company->id, $maker->id);
        $service = app(EInvoiceLifecycleService::class);
        $draft = $service->record($company->id, $maker->id, $this->payload($invoice->id, 'draft'));

        try { $service->record($company->id, $maker->id, $this->payload($invoice->id, 'issued', $draft->id)); $this->fail('Maker must be blocked.'); }
        catch (AuthorizationException) { $this->assertTrue(true); }
        $this->expectException(AuthorizationException::class);
        $service->record($other->id, $otherActor->id, $this->payload($invoice->id, 'draft'));
    }

    public function test_invalid_transition_is_fail_closed(): void
    {
        $company = Company::query()->firstOrFail();
        $maker = User::factory()->create(['company_id' => $company->id]);
        $issuer = User::factory()->create(['company_id' => $company->id]);
        $invoice = $this->invoice($company->id, $maker->id);
        $draft = app(EInvoiceLifecycleService::class)->record($company->id, $maker->id, $this->payload($invoice->id, 'draft'));
        $this->expectException(ValidationException::class);
        app(EInvoiceLifecycleService::class)->record($company->id, $issuer->id, $this->payload($invoice->id, 'adjusted', $draft->id));
    }

    private function invoice(int $companyId, int $createdBy): SalesInvoice
    {
        $customer = DB::table('customers')->insertGetId(['company_id' => $companyId, 'code' => 'TAX-'.uniqid(), 'name' => 'Tax customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return SalesInvoice::withoutGlobalScopes()->create(['company_id' => $companyId, 'customer_id' => $customer, 'invoice_number' => 'SI-TAX', 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'total_amount' => 1, 'status' => 'draft', 'created_by' => $createdBy]);
    }
    private function payload(int $invoiceId, string $status, ?int $supersedes = null): array
    {
        return array_filter(['accounting_document_type' => SalesInvoice::class, 'accounting_document_id' => $invoiceId, 'lifecycle_status' => $status, 'provider_name' => 'test-provider', 'provider_document_id' => $status.'-'.uniqid(), 'document_reference' => 'SI-TAX', 'payload_snapshot' => ['invoice' => 'SI-TAX'], 'supersedes_einvoice_document_id' => $supersedes], fn ($value) => $value !== null);
    }
}
