<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseDashboardDataTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_tenant_scoped_and_never_fabricates_unavailable_accounting_metrics(): void
    {
        $companyA = Company::create(['name' => 'Dashboard A', 'tax_code' => '0101000001', 'address' => 'HN']);
        $companyB = Company::create(['name' => 'Dashboard B', 'tax_code' => '0101000002', 'address' => 'HCM']);
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $this->configureAccountingTenant($userA, $companyA);
        Sanctum::actingAs($userA);

        PurchaseOrder::create([
            'company_id' => $companyA->id,
            'order_number' => 'PO-DASH-A-001',
            'order_date' => '2026-08-22',
            'grand_total' => 80000000,
            'status' => 'completed',
        ]);
        PurchaseOrder::create([
            'company_id' => $companyB->id,
            'order_number' => 'PO-DASH-B-001',
            'order_date' => '2026-08-22',
            'grand_total' => 900000000,
            'status' => 'completed',
        ]);

        $supplierA = Supplier::create(['company_id' => $companyA->id, 'code' => 'NCC-A', 'name' => 'Nhà cung cấp A']);
        $supplierB = Supplier::create(['company_id' => $companyB->id, 'code' => 'NCC-B', 'name' => 'Nhà cung cấp B']);
        PurchaseInvoice::create([
            'company_id' => $companyA->id,
            'supplier_id' => $supplierA->id,
            'supplier_name' => $supplierA->name,
            'invoice_number' => 'HD-DASH-A-001',
            'invoice_date' => '2026-08-22',
            'total_amount' => 120000000,
            'is_posted' => true,
        ]);
        PurchaseInvoice::create([
            'company_id' => $companyB->id,
            'supplier_id' => $supplierB->id,
            'supplier_name' => $supplierB->name,
            'invoice_number' => 'HD-DASH-B-001',
            'invoice_date' => '2026-08-22',
            'total_amount' => 990000000,
            'is_posted' => true,
        ]);

        $response = $this->getJson('/api/v1/purchase/dashboard');

        $response->assertOk()
            ->assertJsonPath('orders.total_amount', 80000000)
            ->assertJsonPath('orders.executed_amount', 80000000)
            ->assertJsonPath('invoices.total_amount', 120000000)
            ->assertJsonPath('invoices.total_amount_decimal', '120000000.00')
            ->assertJsonPath('orders.paid_amount', null)
            ->assertJsonPath('contracts.paid_amount', null)
            ->assertJsonPath('invoices.paid_amount', 0)
            ->assertJsonPath('invoices.paid_amount_decimal', '0.00')
            ->assertJsonPath('invoices.remaining_amount', 120000000)
            ->assertJsonPath('invoices.remaining_amount_decimal', '120000000.00')
            ->assertJsonPath('top_debt_suppliers.0.name', 'Nhà cung cấp A')
            ->assertJsonPath('top_debt_suppliers.0.amount', 120000000)
            ->assertJsonPath('top_debt_suppliers.0.amount_decimal', '120000000.00')
            ->assertJsonCount(1, 'top_purchase_suppliers')
            ->assertJsonPath('top_purchase_suppliers.0.name', 'Nhà cung cấp A')
            ->assertJsonMissing(['amount' => 900000000])
            ->assertJsonMissing(['amount' => 990000000]);

        $availability = $response->json('metric_availability');
        $this->assertSame('unavailable', $availability['orders.paid_amount']['status']);
        $this->assertSame('available', $availability['invoices.paid_amount']['status']);
        $this->assertSame('available', $availability['invoices.remaining_amount']['status']);
        $this->assertSame('available', $availability['top_debt_suppliers']['status']);
    }

    public function test_dashboard_reduces_posted_invoice_allocations_and_reversals_for_ap_metrics(): void
    {
        $company = Company::create(['name' => 'Dashboard AP', 'tax_code' => '0101000004', 'address' => 'HN']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->configureAccountingTenant($user, $company);
        Sanctum::actingAs($user);

        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'NCC-AP', 'name' => 'Nhà cung cấp AP']);
        $invoice = PurchaseInvoice::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'invoice_number' => 'HD-DASH-AP-001',
            'invoice_date' => '2026-08-01',
            'total_amount' => '100000.00',
            'is_posted' => true,
        ]);

        $allocation = [
            'company_id' => $company->id,
            'source_document_type' => 'cash_payment',
            'source_document_id' => 9001,
            'source_line_type' => 'cash_payment_line',
            'source_line_id' => 9002,
            'target_document_type' => 'purchase_invoice',
            'target_document_id' => $invoice->id,
            'allocation_kind' => 'settlement',
            'allocation_direction' => 'reduction',
            'amount_raw' => '40000',
            'amount_scale' => 0,
            'currency_code' => 'VND',
            'functional_currency_code' => 'VND',
            'functional_amount_raw' => '40000',
            'functional_amount_scale' => 0,
            'original_currency_code' => 'VND',
            'original_amount_raw' => '40000',
            'original_amount_scale' => 0,
            'effective_date' => '2026-08-15',
            'status' => 'posted',
            'posted_at' => now(),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('settlement_allocations')->insert(array_merge($allocation, ['source_reference_key' => 'dashboard-ap-payment']));
        DB::table('settlement_allocations')->insert(array_merge($allocation, [
            'source_document_id' => 9003,
            'source_line_id' => 9004,
            'source_reference_key' => 'dashboard-ap-payment-reversal',
            'allocation_direction' => 'reversal',
            'reverses_allocation_id' => DB::table('settlement_allocations')->where('source_reference_key', 'dashboard-ap-payment')->value('id'),
            'amount_raw' => '10000',
            'functional_amount_raw' => '10000',
            'original_amount_raw' => '10000',
            'effective_date' => '2026-08-20',
        ]));

        $response = $this->getJson('/api/v1/purchase/dashboard');

        $response->assertOk()
            ->assertJsonPath('invoices.paid_amount', 30000)
            ->assertJsonPath('invoices.paid_amount_decimal', '30000.00')
            ->assertJsonPath('invoices.remaining_amount', 70000)
            ->assertJsonPath('invoices.remaining_amount_decimal', '70000.00')
            ->assertJsonPath('top_debt_suppliers.0.name', 'Nhà cung cấp AP')
            ->assertJsonPath('top_debt_suppliers.0.amount', 70000)
            ->assertJsonPath('top_debt_suppliers.0.amount_decimal', '70000.00');
        $this->assertSame('settlement_allocations', $response->json('metric_availability')['invoices.remaining_amount']['source']);
    }

    public function test_unposted_purchase_invoice_is_not_reported_as_accounting_recognised_purchase(): void
    {
        $company = Company::create(['name' => 'Posted evidence', 'tax_code' => '0101000003', 'address' => 'DN']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->configureAccountingTenant($user, $company);
        Sanctum::actingAs($user);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'NCC-P', 'name' => 'Nhà cung cấp P']);

        PurchaseInvoice::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'invoice_number' => 'HD-DASH-DRAFT-001',
            'invoice_date' => '2026-08-22',
            'total_amount' => 50000000,
            'is_posted' => false,
        ]);

        $this->getJson('/api/v1/purchase/dashboard')
            ->assertOk()
            ->assertJsonPath('invoices.total_amount', 0)
            ->assertJsonPath('top_purchase_suppliers', []);
    }
}
