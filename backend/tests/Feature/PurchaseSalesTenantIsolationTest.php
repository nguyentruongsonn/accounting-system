<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesQuote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseSalesTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Supplier $supplierA;

    private Supplier $supplierB;

    private Customer $customerB;

    private Item $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create([
            'name' => 'Tenant A',
            'tax_code' => 'TENANT-A',
            'address' => 'A',
        ]);
        $this->companyB = Company::create([
            'name' => 'Tenant B',
            'tax_code' => 'TENANT-B',
            'address' => 'B',
        ]);
        $this->userA = User::factory()->create(['company_id' => $this->companyA->id]);

        $this->supplierA = Supplier::create([
            'company_id' => $this->companyA->id,
            'code' => 'SUP-A',
            'name' => 'Supplier A',
        ]);
        $this->supplierB = Supplier::create([
            'company_id' => $this->companyB->id,
            'code' => 'SUP-B',
            'name' => 'Supplier B',
        ]);
        $this->customerB = Customer::create([
            'company_id' => $this->companyB->id,
            'code' => 'CUS-B',
            'name' => 'Customer B',
        ]);
        $this->itemB = Item::create([
            'company_id' => $this->companyB->id,
            'code' => 'ITEM-B',
            'name' => 'Item B',
            'type' => 'inventory',
        ]);

        Sanctum::actingAs($this->userA);
    }

    public function test_client_company_is_ignored_for_purchase_and_sales_lists_and_creates(): void
    {
        PurchaseOrder::create([
            'company_id' => $this->companyA->id,
            'order_number' => 'PO-A',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplierA->id,
        ]);
        PurchaseOrder::create([
            'company_id' => $this->companyB->id,
            'order_number' => 'PO-B',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplierB->id,
        ]);
        SalesQuote::create([
            'company_id' => $this->companyB->id,
            'quote_number' => 'SQ-B',
            'quote_date' => '2026-08-21',
            'customer_id' => $this->customerB->id,
        ]);

        $this->getJson("/api/v1/purchase/orders?company_id={$this->companyB->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.order_number', 'PO-A');

        $this->getJson("/api/v1/sales/quotes?company_id={$this->companyB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/purchase/orders', [
            'company_id' => $this->companyB->id,
            'order_number' => 'PO-A-NEW',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplierA->id,
        ])->assertCreated();

        $this->assertDatabaseHas('purchase_orders', [
            'order_number' => 'PO-A-NEW',
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_foreign_transaction_route_ids_are_not_disclosed_or_mutated(): void
    {
        $foreignOrder = PurchaseOrder::create([
            'company_id' => $this->companyB->id,
            'order_number' => 'PO-B-SECRET',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplierB->id,
        ]);
        $foreignQuote = SalesQuote::create([
            'company_id' => $this->companyB->id,
            'quote_number' => 'SQ-B-SECRET',
            'quote_date' => '2026-08-21',
            'customer_id' => $this->customerB->id,
        ]);

        $this->getJson("/api/v1/purchase/orders/{$foreignOrder->id}")->assertNotFound();
        $this->deleteJson("/api/v1/purchase/orders/{$foreignOrder->id}")->assertNotFound();
        $this->getJson("/api/v1/sales/quotes/{$foreignQuote->id}")->assertNotFound();
        $this->postJson("/api/v1/sales/quotes/{$foreignQuote->id}/status", [
            'status' => 'approved',
        ])->assertNotFound();

        $this->assertDatabaseHas('purchase_orders', ['id' => $foreignOrder->id]);
        $this->assertDatabaseHas('sales_quotes', [
            'id' => $foreignQuote->id,
            'status' => 'draft',
        ]);
    }

    public function test_ap_and_ar_aging_ignore_client_company_scope(): void
    {
        $customerA = Customer::create([
            'company_id' => $this->companyA->id,
            'code' => 'CUS-A',
            'name' => 'Customer A',
        ]);
        PurchaseInvoice::create([
            'company_id' => $this->companyA->id,
            'supplier_id' => $this->supplierA->id,
            'invoice_number' => 'PI-A-AGING',
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-09-01',
            'total_amount' => 1000,
            'status' => 'Unpaid',
            'is_posted' => true,
        ]);
        SalesInvoice::create([
            'company_id' => $this->companyA->id,
            'customer_id' => $customerA->id,
            'invoice_number' => 'SI-A-AGING',
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-09-01',
            'total_amount' => 2000,
            'status' => 'Unpaid',
            'is_posted' => true,
        ]);

        $this->getJson("/api/v1/purchase/ap-aging?company_id={$this->companyB->id}")
            ->assertOk()
            ->assertJsonPath('0.supplier_code', 'SUP-A');
        $this->getJson("/api/v1/sales/ar-aging?company_id={$this->companyB->id}")
            ->assertOk()
            ->assertJsonPath('0.customer_code', 'CUS-A');
    }

    public function test_foreign_master_and_reference_ids_are_rejected(): void
    {
        $this->postJson('/api/v1/purchase/orders', [
            'order_number' => 'PO-CROSS-SUPPLIER',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplierB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');

        $this->postJson('/api/v1/sales/quotes', [
            'quote_number' => 'SQ-CROSS-MASTER',
            'quote_date' => '2026-08-21',
            'customer_id' => $this->customerB->id,
            'lines' => [[
                'item_id' => $this->itemB->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'lines.0.item_id']);

        $this->postJson('/api/v1/purchase/orders', [
            'order_number' => 'PO-CROSS-REF',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplierA->id,
            'referenced_vouchers' => [[
                'target_type' => SalesQuote::class,
                'target_id' => SalesQuote::create([
                    'company_id' => $this->companyB->id,
                    'quote_number' => 'SQ-B-REF',
                    'quote_date' => '2026-08-21',
                ])->id,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('referenced_vouchers.0.target_id');

        $this->postJson('/api/v1/sales/quotes', [
            'quote_number' => 'SQ-UNSUPPORTED-REF',
            'quote_date' => '2026-08-21',
            'referenced_vouchers' => [[
                'target_type' => 'App\\Models\\UnsupportedTenantDocument',
                'target_id' => 999,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('referenced_vouchers.0.target_type');
    }

    public function test_unassigned_user_is_forbidden_from_purchase_sales_and_aging(): void
    {
        Sanctum::actingAs(User::factory()->create(['company_id' => null]));

        $this->getJson('/api/v1/purchase/orders')->assertForbidden();
        $this->getJson('/api/v1/purchase/ap-aging')->assertForbidden();
        $this->getJson('/api/v1/sales/quotes')->assertForbidden();
        $this->getJson('/api/v1/sales/ar-aging')->assertForbidden();
    }
}
