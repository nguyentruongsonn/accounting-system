<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseInvoicePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchaseInvoicePaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'accounting.enforce_cash_bank_posting_policy' => false,
            'accounting.enforce_cash_bank_posting_approval' => false,
            'accounting.enforce_cash_bank_posting_account_mappings' => false,
        ]);

        $this->company = Company::create(['name' => 'Payment workflow company']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->actor->assignRole(Role::findOrCreate('accountant', 'web'));
        $this->grantGlReportPermissions($this->actor);
        Permission::firstOrCreate(['name' => 'settlement.allocations.create', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo('settlement.allocations.create');
        $this->configureAccountingTenant($this->actor, $this->company);
        Sanctum::actingAs($this->actor);

        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '331',
            'name' => 'Phải trả người bán',
            'type' => 'liability',
            'nature' => 'credit',
            'level' => 1,
            'is_parent' => false,
        ]);
        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '1111',
            'name' => 'Tiền mặt Việt Nam',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'SUP-PAY',
            'name' => 'Nhà cung cấp thanh toán',
            'is_active' => true,
        ]);
    }

    public function test_outstanding_endpoint_returns_only_posted_open_invoices_with_exact_balance(): void
    {
        $open = $this->invoice(1000, true, 'PAY-OPEN');
        $this->invoice(500, false, 'PAY-DRAFT');
        $this->invoice(200, true, 'PAY-VOID', 'voided');

        $response = $this->getJson('/api/v1/purchase/invoices/outstanding?supplier_id='.$this->supplier->id);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.remaining_debt', '1000.00')
            ->assertJsonPath('data.0.status', 'open');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_pay_by_invoice_posts_cash_payment_and_partial_settlement_atomically(): void
    {
        $invoice = $this->invoice(1000, true, 'PAY-PARTIAL');

        $result = app(PurchaseInvoicePaymentService::class)->pay($this->actor, $invoice->id, [
            'voucher_number' => 'PC-PAY-PARTIAL',
            'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22',
            'amount_raw' => '400',
            'debit_account' => '331',
            'credit_account' => '1111',
            'description' => 'Thanh toán một phần PAY-PARTIAL',
        ]);

        $this->assertSame('400', $result['allocation']->amount_raw);
        $this->assertTrue((bool) $result['payment']->is_posted);
        $this->assertSame('600.00', app(PurchaseInvoicePaymentService::class)->outstanding($this->company->id, $this->supplier->id)->first()['remaining_debt']);
        $this->assertDatabaseHas('settlement_allocations', [
            'target_document_type' => 'purchase_invoice',
            'target_document_id' => $invoice->id,
            'source_document_type' => 'cash_payment',
            'amount_raw' => '400',
            'amount_scale' => 0,
        ]);
    }

    public function test_pay_endpoint_enforces_tenant_accounts_and_returns_persisted_evidence(): void
    {
        $invoice = $this->invoice(750, true, 'PAY-API');

        $response = $this->postJson('/api/v1/purchase/invoices/'.$invoice->id.'/pay', [
            'voucher_number' => 'PC-PAY-API',
            'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22',
            'amount_raw' => '250',
            'amount_scale' => 0,
            'debit_account' => '331',
            'credit_account' => '1111',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment.voucher_number', 'PC-PAY-API')
            ->assertJsonPath('data.payment.is_posted', true)
            ->assertJsonPath('data.allocation.target_document_id', $invoice->id)
            ->assertJsonPath('data.allocation.amount_raw', '250');
    }

    public function test_overpayment_rolls_back_cash_payment_and_allocation(): void
    {
        $invoice = $this->invoice(1000, true, 'PAY-OVER');

        $this->expectException(\Throwable::class);
        try {
            app(PurchaseInvoicePaymentService::class)->pay($this->actor, $invoice->id, [
                'voucher_number' => 'PC-PAY-OVER',
                'voucher_date' => '2026-08-22',
                'posting_date' => '2026-08-22',
                'amount_raw' => '1001',
                'debit_account' => '331',
                'credit_account' => '1111',
            ]);
        } finally {
            $this->assertDatabaseMissing('cash_payments', ['voucher_number' => 'PC-PAY-OVER']);
            $this->assertDatabaseMissing('settlement_allocations', ['target_document_id' => $invoice->id]);
        }
    }

    private function invoice(int $amount, bool $posted, string $number, string $status = 'draft'): PurchaseInvoice
    {
        return PurchaseInvoice::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => $number,
            'invoice_date' => '2026-08-01',
            'accounting_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'payment_method' => 'unpaid',
            'description' => 'Hóa đơn '.$number,
            'currency' => 'VND',
            'sub_total' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'status' => $status,
            'is_posted' => $posted,
        ]);
    }
}
