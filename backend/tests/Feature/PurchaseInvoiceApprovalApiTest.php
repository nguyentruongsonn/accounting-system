<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\PurchaseInvoicePostingApprovalGate;
use App\Services\PurchaseInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Contract tests for the deliberately narrow purchase-invoice approval API.
 * These tests keep the client from selecting the approval subject, evidence,
 * policy key, or snapshot that the posting gate will later trust.
 */
class PurchaseInvoiceApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $maker;
    private User $viewer;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['purchase.invoices.view', 'purchase.invoices.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->company = Company::create(['name' => 'Purchase approval API tenant', 'tax_code' => 'PURCHASE-APPROVAL-API']);
        $this->maker = User::factory()->create(['company_id' => $this->company->id]);
        $this->viewer = User::factory()->create(['company_id' => $this->company->id]);
        $this->maker->givePermissionTo(['purchase.invoices.view', 'purchase.invoices.update']);
        $this->viewer->givePermissionTo('purchase.invoices.view');
        $this->configureAccountingTenant($this->maker, $this->company);

        foreach ([['1561', 'Hàng hóa', 'asset', 'debit'], ['331', 'Phải trả người bán', 'liability', 'credit']] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }

        $workflow = app(ApprovalWorkflowService::class);
        $draft = $workflow->createDraftPolicy($this->maker, PurchaseInvoicePostingApprovalGate::APPROVAL_KEY, 'purchase-api-v1', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), [['required_approvals' => 1]], true);
        $workflow->activatePolicy($this->viewer, $draft);
    }

    public function test_rbac_and_tenant_isolation_do_not_disclose_purchase_approval_evidence(): void
    {
        $invoice = $this->newInvoice('API-RBAC-1');
        $unprivileged = User::factory()->create(['company_id' => $this->company->id]);

        Sanctum::actingAs($unprivileged);
        $this->getJson($this->url($invoice))->assertForbidden();
        $this->postJson($this->url($invoice), ['reference_note' => 'attempt'])->assertForbidden();

        $other = Company::create(['name' => 'Other purchase approval tenant', 'tax_code' => 'PURCHASE-APPROVAL-OTHER']);
        $outsider = User::factory()->create(['company_id' => $other->id]);
        $outsider->givePermissionTo(['purchase.invoices.view', 'purchase.invoices.update']);
        Sanctum::actingAs($outsider);
        $this->getJson($this->url($invoice))->assertNotFound()->assertJsonMissingPath('data.snapshot_hash');
        $this->postJson($this->url($invoice), ['reference_note' => 'attempt'])->assertNotFound();
        $this->assertDatabaseCount('approval_requests', 0);
    }

    public function test_unassigned_actor_cannot_read_purchase_approval_evidence(): void
    {
        $invoice = $this->newInvoice('API-UNASSIGNED-1');
        $unassigned = User::factory()->create(['company_id' => null]);
        $unassigned->givePermissionTo('purchase.invoices.view');
        Sanctum::actingAs($unassigned);

        $this->getJson($this->url($invoice))
            ->assertForbidden()
            ->assertJsonMissingPath('data.snapshot_hash');
    }

    public function test_store_derives_subject_snapshot_and_reference_evidence_on_server_and_prevents_duplicate_current_pending(): void
    {
        $invoice = $this->newInvoice('API-EVIDENCE-1');
        $expectedHash = PurchaseInvoicePostingApprovalGate::snapshotHash($invoice->fresh('lines'));
        Sanctum::actingAs($this->maker);

        $response = $this->postJson($this->url($invoice), [
            'reference_note' => 'Đã kiểm tra biên bản nhận hàng',
            // Unvalidated client fields must not influence the stored request.
            'approval_key' => 'forged.key',
            'subject_type' => 'forged_subject',
            'subject_id' => 999999,
            'purchase_invoice_snapshot_hash' => str_repeat('f', 64),
            'reference_document_id' => 999999,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.approval_key', PurchaseInvoicePostingApprovalGate::APPROVAL_KEY)
            ->assertJsonPath('data.evidence.reference_document_type', PurchaseInvoicePostingApprovalGate::SUBJECT_TYPE)
            ->assertJsonPath('data.evidence.reference_document_id', $invoice->id)
            ->assertJsonPath('data.evidence.reference_note', 'Đã kiểm tra biên bản nhận hàng')
            ->assertJsonPath('data.evidence.snapshot_hash', $expectedHash)
            ->assertJsonPath('data.evidence.is_current', true);

        $request = ApprovalRequest::withoutGlobalScope('company')->sole();
        $this->assertSame($this->company->id, $request->company_id);
        $this->assertSame(PurchaseInvoicePostingApprovalGate::SUBJECT_TYPE, $request->subject_type);
        $this->assertSame((string) $invoice->id, $request->subject_id);
        $this->assertSame($this->maker->id, $request->requested_by);
        $this->assertSame($expectedHash, $request->request_evidence['purchase_invoice_snapshot_hash']);
        $this->assertSame($invoice->id, $request->request_evidence['reference_document_id']);
        $this->assertSame(PurchaseInvoicePostingApprovalGate::SUBJECT_TYPE, $request->request_evidence['reference_document_type']);

        $this->postJson($this->url($invoice), ['reference_note' => 'duplicate'])->assertUnprocessable()
            ->assertJsonValidationErrors('approval');
        $this->assertDatabaseCount('approval_requests', 1);
    }

    public function test_posted_invoice_cannot_create_new_approval_request(): void
    {
        $invoice = $this->newInvoice('API-POSTED-1');
        PurchaseInvoice::withoutGlobalScope('company')->whereKey($invoice->id)->update(['is_posted' => true]);
        Sanctum::actingAs($this->maker);

        $this->postJson($this->url($invoice), ['reference_note' => 'too late'])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertDatabaseCount('approval_requests', 0);
    }

    public function test_edit_makes_existing_request_stale_and_allows_one_new_current_request(): void
    {
        $invoice = $this->newInvoice('API-STALE-1');
        Sanctum::actingAs($this->maker);
        $first = $this->postJson($this->url($invoice), ['reference_note' => 'version one'])->assertCreated();
        $firstHash = $first->json('data.evidence.snapshot_hash');

        PurchaseInvoice::withoutGlobalScope('company')->whereKey($invoice->id)->update(['description' => 'Material correction after request']);
        $listing = $this->getJson($this->url($invoice))->assertOk();
        $listing->assertJsonPath('data.requests.0.evidence.snapshot_hash', $firstHash)
            ->assertJsonPath('data.requests.0.evidence.is_current', false);

        $second = $this->postJson($this->url($invoice), ['reference_note' => 'version two'])->assertCreated();
        $this->assertNotSame($firstHash, $second->json('data.evidence.snapshot_hash'));
        $this->assertTrue($second->json('data.evidence.is_current'));
        $this->assertDatabaseCount('approval_requests', 2);
    }

    private function url(PurchaseInvoice $invoice): string
    {
        return "/api/v1/purchase/invoices/{$invoice->id}/approval-requests";
    }

    private function newInvoice(string $number): PurchaseInvoice
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Supplier '.$number]);

        return app(PurchaseInvoiceService::class)->create([
            'company_id' => $this->company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'due_date' => '2026-09-22',
            'lines' => [[
                'description' => 'Purchase approval API evidence',
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ]);
    }
}
