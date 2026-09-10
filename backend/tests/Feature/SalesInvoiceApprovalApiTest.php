<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\SalesInvoicePostingApprovalGate;
use App\Services\SalesInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Contract tests for the evidence-only sales-invoice approval API. */
class SalesInvoiceApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $maker;
    private User $viewer;

    protected function grantGlReportPermissionsToLegacyActors(): bool { return false; }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['sales.invoices.view', 'sales.invoices.update'] as $permission) Permission::findOrCreate($permission, 'web');

        $this->company = Company::create(['name' => 'Sales approval API tenant', 'tax_code' => 'SALES-APPROVAL-API']);
        $this->maker = User::factory()->create(['company_id' => $this->company->id]);
        $this->viewer = User::factory()->create(['company_id' => $this->company->id]);
        $this->maker->givePermissionTo(['sales.invoices.view', 'sales.invoices.update']);
        $this->viewer->givePermissionTo('sales.invoices.view');
        $this->configureAccountingTenant($this->maker, $this->company);

        foreach ([['131', 'Phải thu', 'asset', 'debit'], ['5111', 'Doanh thu', 'revenue', 'credit'], ['33311', 'Thuế GTGT', 'liability', 'credit']] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
        $workflow = app(ApprovalWorkflowService::class);
        $policy = $workflow->createDraftPolicy($this->maker, SalesInvoicePostingApprovalGate::APPROVAL_KEY, 'sales-api-v1', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), [['required_approvals' => 1]], true);
        $workflow->activatePolicy($this->viewer, $policy);
    }

    public function test_rbac_and_tenant_isolation_do_not_disclose_sales_approval_evidence(): void
    {
        $invoice = $this->newInvoice('SALES-API-RBAC-1');
        $unprivileged = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($unprivileged);
        $this->getJson($this->url($invoice))->assertForbidden();
        $this->postJson($this->url($invoice), ['reference_note' => 'attempt'])->assertForbidden();

        $other = Company::create(['name' => 'Other sales approval tenant', 'tax_code' => 'SALES-APPROVAL-OTHER']);
        $outsider = User::factory()->create(['company_id' => $other->id]);
        $outsider->givePermissionTo(['sales.invoices.view', 'sales.invoices.update']);
        Sanctum::actingAs($outsider);
        $this->getJson($this->url($invoice))->assertNotFound()->assertJsonMissingPath('data.snapshot_hash');
        $this->postJson($this->url($invoice), ['reference_note' => 'attempt'])->assertNotFound();
        $this->assertDatabaseCount('approval_requests', 0);
    }

    public function test_store_derives_evidence_server_side_and_rejects_duplicate_current_pending(): void
    {
        $invoice = $this->newInvoice('SALES-API-EVIDENCE-1');
        $expectedHash = SalesInvoicePostingApprovalGate::snapshotHash($invoice->fresh('lines'));
        Sanctum::actingAs($this->maker);
        $response = $this->postJson($this->url($invoice), [
            'reference_note' => 'Đã kiểm tra chứng từ bán hàng',
            'approval_key' => 'forged.key', 'subject_type' => 'forged_subject', 'subject_id' => 999999,
            'sales_invoice_snapshot_hash' => str_repeat('f', 64), 'reference_document_id' => 999999,
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.approval_key', SalesInvoicePostingApprovalGate::APPROVAL_KEY)
            ->assertJsonPath('data.evidence.reference_document_type', SalesInvoicePostingApprovalGate::SUBJECT_TYPE)
            ->assertJsonPath('data.evidence.reference_document_id', $invoice->id)
            ->assertJsonPath('data.evidence.reference_note', 'Đã kiểm tra chứng từ bán hàng')
            ->assertJsonPath('data.evidence.snapshot_hash', $expectedHash)
            ->assertJsonPath('data.evidence.is_current', true);

        $approval = ApprovalRequest::withoutGlobalScope('company')->sole();
        $this->assertSame($this->company->id, $approval->company_id);
        $this->assertSame(SalesInvoicePostingApprovalGate::SUBJECT_TYPE, $approval->subject_type);
        $this->assertSame((string) $invoice->id, $approval->subject_id);
        $this->assertSame($this->maker->id, $approval->requested_by);
        $this->assertSame($expectedHash, $approval->request_evidence['sales_invoice_snapshot_hash']);
        $this->assertSame($invoice->id, $approval->request_evidence['reference_document_id']);
        $this->postJson($this->url($invoice), ['reference_note' => 'duplicate'])->assertUnprocessable()->assertJsonValidationErrors('approval');
        $this->assertDatabaseCount('approval_requests', 1);
    }

    public function test_posted_invoice_cannot_create_new_approval_request(): void
    {
        $invoice = $this->newInvoice('SALES-API-POSTED-1');
        SalesInvoice::withoutGlobalScope('company')->whereKey($invoice->id)->update(['is_posted' => true]);
        Sanctum::actingAs($this->maker);
        $this->postJson($this->url($invoice), ['reference_note' => 'too late'])->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertDatabaseCount('approval_requests', 0);
    }

    public function test_edit_makes_request_stale_and_allows_one_new_current_request(): void
    {
        $invoice = $this->newInvoice('SALES-API-STALE-1');
        Sanctum::actingAs($this->maker);
        $first = $this->postJson($this->url($invoice), ['reference_note' => 'version one'])->assertCreated();
        $firstHash = $first->json('data.evidence.snapshot_hash');
        SalesInvoice::withoutGlobalScope('company')->whereKey($invoice->id)->update(['description' => 'Material correction after request']);
        $this->getJson($this->url($invoice))->assertOk()
            ->assertJsonPath('data.requests.0.evidence.snapshot_hash', $firstHash)
            ->assertJsonPath('data.requests.0.evidence.is_current', false);
        $second = $this->postJson($this->url($invoice), ['reference_note' => 'version two'])->assertCreated();
        $this->assertNotSame($firstHash, $second->json('data.evidence.snapshot_hash'));
        $this->assertTrue($second->json('data.evidence.is_current'));
        $this->assertDatabaseCount('approval_requests', 2);
    }

    private function url(SalesInvoice $invoice): string { return "/api/v1/sales/invoices/{$invoice->id}/approval-requests"; }

    private function newInvoice(string $number): SalesInvoice
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Customer '.$number]);
        return app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'invoice_number' => $number,
            'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'due_date' => '2026-09-22',
            'lines' => [['description' => 'Sales approval API evidence', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '10', 'credit_account' => '5111', 'tax_account' => '33311']],
        ]);
    }
}
