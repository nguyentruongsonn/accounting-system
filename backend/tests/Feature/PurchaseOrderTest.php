<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Supplier $supplier;

    protected Employee $employee;

    protected Item $itemA;

    protected Item $itemB;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Công ty TNHH Mua Hàng Chuẩn MISA',
            'tax_code' => '0109998888',
            'address' => 'Hà Nội, Việt Nam',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);
        Sanctum::actingAs($this->user);
        $this->configureAccountingTenant($this->user, $this->company);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        // Chart of Accounts for VAS TT200 GL integration
        ChartOfAccount::firstOrCreate(['company_id' => $this->company->id, 'code' => '1561'], ['name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);
        ChartOfAccount::firstOrCreate(['company_id' => $this->company->id, 'code' => '331'], ['name' => 'Phải trả người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::firstOrCreate(['company_id' => $this->company->id, 'code' => '1331'], ['name' => 'Thuế GTGT được khấu trừ', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC_HOAPHAT',
            'name' => 'Tập đoàn Hòa Phát',
            'address' => 'KCN Phố Nối A, Hưng Yên',
            'tax_code' => '0900123456',
            'contact_name' => 'Nguyễn Văn Quyền',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'code' => 'NV001',
            'name' => 'Trần Thị Thu Mua',
            'email' => 'thumua@company.vn',
        ]);

        $this->itemA = Item::create([
            'company_id' => $this->company->id,
            'code' => 'THEP-PHI-10',
            'name' => 'Thép cuộn phi 10 Hòa Phát',
            'unit' => 'Cuộn',
            'cost_price' => 2000000,
            'type' => 'inventory',
        ]);

        $this->itemB = Item::create([
            'company_id' => $this->company->id,
            'code' => 'THEP-PHI-20',
            'name' => 'Thép thanh vằn phi 20',
            'unit' => 'Cây',
            'cost_price' => 500000,
            'type' => 'inventory',
        ]);

        $this->warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO_PO_TEST'],
            [
                'company_id' => $this->company->id,
                'name' => 'Kho tổng vật tư PO',
                'default_account' => '1561',
            ]
        );
    }

    public function test_can_get_next_code_for_purchase_order(): void
    {
        $response = $this->getJson('/api/v1/purchase/orders/next-code');
        $response->assertStatus(200)
            ->assertJsonStructure(['code', 'next_code', 'data' => ['code', 'next_code']]);

        // Preserve the controller's existing Vietnamese purchase-order
        // numbering contract rather than inventing a new PO prefix.
        $this->assertEquals('ĐMH00001', $response->json('next_code'));
    }

    public function test_next_code_advances_past_existing_prefixed_number_instead_of_counting_rows(): void
    {
        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'ĐMH00007',
            'order_date' => '2026-08-21',
        ]);

        $response = $this->getJson('/api/v1/purchase/orders/next-code');

        $response->assertOk()
            ->assertJsonPath('next_code', 'ĐMH00008')
            ->assertJsonPath('data.next_code', 'ĐMH00008');
    }

    public function test_can_create_purchase_order_with_line_items(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'order_number' => 'PO-2026-0001',
            'order_date' => '2026-08-21',
            'delivery_date' => '2026-08-28',
            'supplier_id' => $this->supplier->id,
            'supplier_code' => $this->supplier->code,
            'supplier_name' => $this->supplier->name,
            'supplier_address' => $this->supplier->address,
            'tax_code' => $this->supplier->tax_code,
            'contact_person' => $this->supplier->contact_name,
            'employee_id' => $this->employee->id,
            'buyer_name' => $this->employee->name,
            'payment_terms' => 'ĐKTT30',
            'due_days' => 30,
            'delivery_address' => 'Kho tổng vật tư',
            'description' => 'Đơn đặt mua thép thi công công trình',
            'total_amount' => 25000000,
            'discount_amount' => 1000000,
            'vat_amount' => 2400000,
            'grand_total' => 26400000,
            'status' => 'pending',
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'item_code' => $this->itemA->code,
                    'item_name' => $this->itemA->name,
                    'unit' => 'Cuộn',
                    'quantity' => 10,
                    'unit_price' => 2000000,
                    'amount' => 20000000,
                    'discount_rate' => 5,
                    'discount_amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1900000,
                ],
                [
                    'item_id' => $this->itemB->id,
                    'item_code' => $this->itemB->code,
                    'item_name' => $this->itemB->name,
                    'unit' => 'Cây',
                    'quantity' => 10,
                    'unit_price' => 500000,
                    'amount' => 5000000,
                    'discount_rate' => 0,
                    'discount_amount' => 0,
                    'tax_rate' => 10,
                    'tax_amount' => 500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/orders', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('order_number', 'PO-2026-0001')
            ->assertJsonCount(2, 'lines');

        $this->assertDatabaseHas('purchase_orders', [
            'order_number' => 'PO-2026-0001',
            'supplier_id' => $this->supplier->id,
            'grand_total' => 26400000,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('purchase_order_lines', [
            'item_id' => $this->itemA->id,
            'quantity' => 10,
            'unit_price' => 2000000,
        ]);
        $this->assertDatabaseHas('purchase_order_lines', [
            'item_id' => $this->itemB->id,
            'quantity' => 10,
            'unit_price' => 500000,
        ]);
    }

    public function test_purchase_order_totals_use_exact_line_arithmetic(): void
    {
        $response = $this->postJson('/api/v1/purchase/orders', [
            'order_number' => 'PO-EXACT-0001',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplier->id,
            'lines' => [[
                'item_id' => $this->itemA->id,
                'quantity' => '3.00',
                'unit_price' => '1234.56',
                'discount_rate' => '1.00',
                'tax_rate' => '10.00',
            ]],
        ]);
        $response->assertStatus(201)
            ->assertJsonPath('sub_total', '3703.68')
            ->assertJsonPath('discount_amount', '37.04')
            ->assertJsonPath('vat_amount', '366.66')
            ->assertJsonPath('grand_total', '4033.30');
    }

    public function test_purchase_order_creation_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/purchase/orders', [
            'company_id' => $this->company->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['order_number', 'order_date']);
    }

    public function test_can_list_and_filter_purchase_orders(): void
    {
        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-LIST-001',
            'order_date' => '2026-08-20',
            'supplier_name' => 'Nhà cung cấp An Phát',
            'status' => 'pending',
            'grand_total' => 10000000,
        ]);

        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-LIST-002',
            'order_date' => '2026-08-21',
            'supplier_name' => 'Nhà cung cấp Bảo Minh',
            'status' => 'completed',
            'grand_total' => 30000000,
        ]);

        // List all
        $resAll = $this->getJson('/api/v1/purchase/orders');
        $resAll->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($resAll->json()));

        // Filter by status completed
        $resCompleted = $this->getJson('/api/v1/purchase/orders?status=completed');
        $resCompleted->assertStatus(200);
        $orders = $resCompleted->json();
        $this->assertTrue(collect($orders)->every(fn ($o) => $o['status'] === 'completed'));

        // Search by keyword
        $resSearch = $this->getJson('/api/v1/purchase/orders?search=Bảo Minh');
        $resSearch->assertStatus(200);
        $this->assertTrue(collect($resSearch->json())->contains('order_number', 'PO-LIST-002'));
    }

    public function test_can_show_purchase_order_with_relations(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-SHOW-001',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplier->id,
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'grand_total' => 5000000,
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->itemA->id,
            'quantity' => 2,
            'unit_price' => 2500000,
            'amount' => 5000000,
        ]);

        $response = $this->getJson('/api/v1/purchase/orders/'.$po->id);
        $response->assertStatus(200)
            ->assertJsonPath('order_number', 'PO-SHOW-001')
            ->assertJsonPath('supplier.id', $this->supplier->id)
            ->assertJsonPath('employee.id', $this->employee->id)
            ->assertJsonCount(1, 'lines');

        $this->getJson('/api/v1/purchase/orders/999999')->assertStatus(404);
    }

    public function test_can_update_purchase_order_and_replace_line_items(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-UPD-001',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplier->id,
            'description' => 'Original description',
            'grand_total' => 10000000,
        ]);

        $oldLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->itemA->id,
            'quantity' => 5,
            'unit_price' => 2000000,
            'amount' => 10000000,
        ]);

        $updatePayload = [
            'description' => 'Updated description',
            'grand_total' => 7500000,
            'lines' => [
                [
                    'item_id' => $this->itemB->id,
                    'item_code' => $this->itemB->code,
                    'item_name' => $this->itemB->name,
                    'unit' => 'Cây',
                    'quantity' => 15,
                    'unit_price' => 500000,
                    'amount' => 7500000,
                ],
            ],
        ];

        $response = $this->putJson('/api/v1/purchase/orders/'.$po->id, $updatePayload);
        $response->assertStatus(200)
            ->assertJsonPath('description', 'Updated description')
            ->assertJsonCount(1, 'lines');

        $this->assertDatabaseMissing('purchase_order_lines', ['id' => $oldLine->id]);
        $this->assertDatabaseHas('purchase_order_lines', [
            'purchase_order_id' => $po->id,
            'item_id' => $this->itemB->id,
            'quantity' => 15,
        ]);
    }

    public function test_cannot_update_purchase_order_with_posted_dependent_document(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-DEPENDENCY-001',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplier->id,
            'description' => 'Đơn mua nguồn',
            'status' => 'processing',
            'grand_total' => 1000000,
        ]);

        $dependent = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => 2,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GJ-PO-DEPENDENCY-001',
            'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22',
            'description' => 'Chứng từ đã ghi sổ phụ thuộc đơn mua',
            'total_amount' => 1000000,
            'status' => 'posted',
        ]);
        $dependent->syncReferences([[
            'target_type' => PurchaseOrder::class,
            'target_id' => $po->id,
            'voucher_type' => 'Đơn mua hàng',
            'voucher_number' => $po->order_number,
            'voucher_date' => $po->order_date->toDateString(),
            'total_amount' => $po->grand_total,
        ]]);

        $response = $this->putJson('/api/v1/purchase/orders/'.$po->id, [
            'description' => 'Không được cập nhật sau khi phát sinh chứng từ đã ghi sổ',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('dependent_documents');
        $this->assertStringContainsString(
            'GJ-PO-DEPENDENCY-001',
            implode(' ', $response->json('errors.dependent_documents', []))
        );
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'description' => 'Đơn mua nguồn',
        ]);
    }

    public function test_can_update_order_status_without_affecting_lines(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-ST-001',
            'order_date' => '2026-08-21',
            'status' => 'pending',
            'grand_total' => 4000000,
        ]);

        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->itemA->id,
            'quantity' => 2,
            'unit_price' => 2000000,
            'amount' => 4000000,
        ]);

        $response = $this->putJson('/api/v1/purchase/orders/'.$po->id, [
            'status' => 'processing',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'processing');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'processing',
        ]);
        $this->assertDatabaseHas('purchase_order_lines', [
            'id' => $line->id,
        ]);
    }

    public function test_purchase_order_status_lifecycle_transitions(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-LIFE-001',
            'order_date' => '2026-08-21',
            'status' => 'pending',
        ]);

        // Pending -> Processing
        $this->putJson('/api/v1/purchase/orders/'.$po->id, ['status' => 'processing'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'processing');

        // Processing -> Completed
        $this->putJson('/api/v1/purchase/orders/'.$po->id, ['status' => 'completed'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        // Completed -> Cancelled
        $this->putJson('/api/v1/purchase/orders/'.$po->id, ['status' => 'cancelled'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_can_delete_purchase_order_and_cascade_lines(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-DEL-001',
            'order_date' => '2026-08-21',
            'status' => 'cancelled',
        ]);

        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->itemA->id,
            'quantity' => 1,
            'unit_price' => 2000000,
        ]);

        $response = $this->deleteJson('/api/v1/purchase/orders/'.$po->id);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('purchase_orders', ['id' => $po->id]);
        $this->assertDatabaseMissing('purchase_order_lines', ['id' => $line->id]);
    }

    public function test_purchase_order_to_purchase_invoice_conversion_and_gl_posting(): void
    {
        // 1. Create Purchase Order
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-CONV-2026',
            'order_date' => '2026-08-20',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'sub_total' => 20000000,
            'tax_amount' => 2000000,
            'grand_total' => 22000000,
            'status' => 'processing',
            'description' => 'Đơn đặt mua 10 cuộn thép phi 10',
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->itemA->id,
            'item_code' => $this->itemA->code,
            'item_name' => $this->itemA->name,
            'unit' => 'Cuộn',
            'quantity' => 10,
            'unit_price' => 2000000,
            'amount' => 20000000,
            'tax_rate' => 10,
            'tax_amount' => 2000000,
        ]);

        // 2. Create Purchase Invoice referencing PO
        $invoicePayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'HDMH-CONV-2026',
            'invoice_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'unpaid',
            'sub_total' => 20000000,
            'tax_amount' => 2000000,
            'total_amount' => 22000000,
            'grand_total' => 22000000,
            'description' => 'Hóa đơn mua hàng từ đơn PO-CONV-2026',
            'referenced_vouchers' => [
                [
                    'target_type' => PurchaseOrder::class,
                    'target_id' => $po->id,
                    'voucher_type' => 'Đơn mua hàng',
                    'voucher_number' => $po->order_number,
                    'voucher_date' => '2026-08-20',
                    'total_amount' => 22000000,
                    'description' => 'Đơn đặt hàng mua tham chiếu',
                ],
            ],
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'item_code' => $this->itemA->code,
                    'item_name' => $this->itemA->name,
                    'unit' => 'Cuộn',
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 2000000,
                    'amount' => 20000000,
                    'tax_rate' => 10,
                    'tax_amount' => 2000000,
                    'tax_account' => '1331',
                    'order_id' => $po->id,
                ],
            ],
        ];

        $invRes = $this->postJson('/api/v1/purchase/invoices', $invoicePayload);
        $invRes->assertStatus(201);
        $invoiceId = $invRes->json('id');

        // 3. Post Invoice to GL
        $postRes = $this->postJson('/api/v1/purchase/invoices/'.$invoiceId.'/post');
        $postRes->assertStatus(200);

        $invoice = PurchaseInvoice::with(['references', 'journalEntry.lines'])->find($invoiceId);
        $this->assertTrue((bool) $invoice->is_posted);
        $this->assertNotNull($invoice->journal_entry_id);

        // Strict GL balancing check
        $sumDebit = $invoice->journalEntry->lines->sum('debit_amount');
        $sumCredit = $invoice->journalEntry->lines->sum('credit_amount');
        $this->assertEquals(22000000, $sumDebit);
        $this->assertEquals(22000000, $sumCredit);

        // 4. Verify Voucher References
        $this->assertCount(1, $invoice->references);
        $this->assertEquals(PurchaseOrder::class, $invoice->references->first()->target_type);
        $this->assertEquals($po->id, $invoice->references->first()->target_id);

        // 5. Reverse reference check
        $this->assertCount(1, $po->referencedBy);
        $this->assertEquals(PurchaseInvoice::class, $po->referencedBy->first()->source_type);
        $this->assertEquals($invoiceId, $po->referencedBy->first()->source_id);

        // 6. Complete PO
        $this->putJson('/api/v1/purchase/orders/'.$po->id, ['status' => 'completed'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');
    }

    public function test_purchase_order_appears_in_voucher_reference_search(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-REF-SEARCH-01',
            'order_date' => '2026-08-21',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'total_amount' => 15000000,
            'grand_total' => 15000000,
            'status' => 'pending',
            'description' => 'Đơn mua tham chiếu tìm kiếm',
        ]);

        $response = $this->getJson('/api/v1/voucher-references/search?module_group=purchase');
        $response->assertStatus(200);

        $items = collect($response->json('data') ?? $response->json());
        $found = $items->firstWhere('voucher_number', 'PO-REF-SEARCH-01');
        $this->assertNotNull($found, 'PurchaseOrder must be searchable in voucher references');
        $this->assertEquals('PurchaseOrder', $found['model']);
    }

    public function test_purchase_dashboard_order_metrics_aggregation(): void
    {
        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-DASH-001',
            'order_date' => '2026-08-21',
            'grand_total' => 50000000,
            'status' => 'completed',
        ]);

        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-DASH-002',
            'order_date' => '2026-08-21',
            'grand_total' => 30000000,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/v1/purchase/dashboard');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'orders' => [
                    'total_amount',
                    'executed_amount',
                    'paid_amount',
                    'remaining_amount',
                ],
            ]);

        $this->assertGreaterThanOrEqual(80000000, $response->json('orders.total_amount'));
        $this->assertGreaterThanOrEqual(50000000, $response->json('orders.executed_amount'));
    }
}
