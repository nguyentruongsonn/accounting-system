<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\SalesInvoiceCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesInvoiceCollectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'accounting.enforce_cash_bank_posting_policy' => false,
            'accounting.enforce_cash_bank_posting_approval' => false,
            'accounting.enforce_cash_bank_posting_account_mappings' => false,
        ]);

        $this->company = Company::create(['name' => 'Collection workflow company']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->actor->assignRole(Role::findOrCreate('accountant', 'web'));
        $this->grantGlReportPermissions($this->actor);
        Permission::firstOrCreate(['name' => 'settlement.allocations.create', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo('settlement.allocations.create');
        $this->configureAccountingTenant($this->actor, $this->company);
        Sanctum::actingAs($this->actor);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Tiền mặt Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'CUS-COLLECT', 'name' => 'Khách hàng thu tiền', 'is_active' => true]);
    }

    public function test_outstanding_endpoint_returns_posted_open_receivables(): void
    {
        $open = $this->invoice(1200, true, 'COLLECT-OPEN');
        $this->invoice(500, false, 'COLLECT-DRAFT');

        $response = $this->getJson('/api/v1/sales/invoices/outstanding?customer_id='.$this->customer->id);
        $response->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.remaining_amount', '1200.00')
            ->assertJsonPath('data.0.status', 'open');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_collection_posts_cash_receipt_and_partial_ar_settlement(): void
    {
        $invoice = $this->invoice(1200, true, 'COLLECT-PARTIAL');
        $result = app(SalesInvoiceCollectionService::class)->collect($this->actor, $invoice->id, [
            'voucher_number' => 'PT-COLLECT-PARTIAL',
            'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22',
            'amount_raw' => '450',
            'debit_account' => '1111',
            'credit_account' => '131',
        ]);

        $this->assertSame('450', $result['allocation']->amount_raw);
        $this->assertTrue((bool) $result['receipt']->is_posted);
        $this->assertSame('750.00', app(SalesInvoiceCollectionService::class)->outstanding($this->company->id, $this->customer->id)->first()['remaining_amount']);
        $this->assertDatabaseHas('settlement_allocations', ['target_document_type' => 'sales_invoice', 'target_document_id' => $invoice->id, 'source_document_type' => 'cash_receipt', 'amount_raw' => '450', 'amount_scale' => 0]);
    }

    public function test_collection_endpoint_returns_persisted_receipt_and_allocation(): void
    {
        $invoice = $this->invoice(900, true, 'COLLECT-API');

        $response = $this->postJson('/api/v1/sales/invoices/'.$invoice->id.'/collect', [
            'voucher_number' => 'PT-COLLECT-API',
            'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22',
            'amount_raw' => '250',
            'amount_scale' => 0,
            'debit_account' => '1111',
            'credit_account' => '131',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.receipt.voucher_number', 'PT-COLLECT-API')
            ->assertJsonPath('data.receipt.is_posted', true)
            ->assertJsonPath('data.allocation.target_document_id', $invoice->id)
            ->assertJsonPath('data.allocation.amount_raw', '250');
    }

    public function test_collection_overpayment_rolls_back_receipt(): void
    {
        $invoice = $this->invoice(1200, true, 'COLLECT-OVER');
        $this->expectException(\Throwable::class);
        try {
            app(SalesInvoiceCollectionService::class)->collect($this->actor, $invoice->id, [
                'voucher_number' => 'PT-COLLECT-OVER', 'voucher_date' => '2026-08-22', 'posting_date' => '2026-08-22',
                'amount_raw' => '1201', 'debit_account' => '1111', 'credit_account' => '131',
            ]);
        } finally {
            $this->assertDatabaseMissing('cash_receipts', ['voucher_number' => 'PT-COLLECT-OVER']);
            $this->assertDatabaseMissing('settlement_allocations', ['target_document_id' => $invoice->id]);
        }
    }

    private function invoice(int $amount, bool $posted, string $number): SalesInvoice
    {
        return SalesInvoice::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'customer_name' => $this->customer->name,
            'invoice_number' => $number, 'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'payment_method' => 'unpaid', 'sub_total' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
            'status' => $posted ? 'posted' : 'draft', 'payment_status' => 'Unpaid', 'is_posted' => $posted,
        ]);
    }
}
