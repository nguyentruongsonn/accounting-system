<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdversarialPurchaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Supplier $supplier;

    protected Warehouse $warehouse;

    protected Item $item;

    protected BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Công ty TNHH Thử Nghiệm Đối Kháng M1',
            'tax_code' => '0108889999',
            'address' => 'Hà Nội, Việt Nam',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);
        // Posting is intentionally restricted to the canonical accountant or
        // admin role; this lifecycle fixture represents an accountant.
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        // Standard VAS TT200 Chart of Accounts
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt Việt Nam đồng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng Việt Nam đồng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ của hàng hóa, dịch vụ', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '152',  'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '331',  'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '632',  'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '642',  'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit'],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $acc['code'],
                'name' => $acc['name'],
                'type' => $acc['type'],
                'nature' => $acc['nature'],
                'level' => 2,
                'is_parent' => 0,
            ]);
        }

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC-ADVERSARIAL-01',
            'name' => 'Tập đoàn Thép Việt Nhật',
            'tax_code' => '0102030405',
            'address' => 'KCN Thăng Long, Đông Anh, Hà Nội',
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-TONG',
            'name' => 'Kho Tổng Miền Bắc',
            'default_account' => '1561',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'THEP-CUON-VN',
            'name' => 'Thép cuộn Việt Nhật Phi 10',
            'unit' => 'Cuộn',
            'type' => 'inventory',
            'inventory_account' => '1561',
            'cost_price' => 2500000,
            'selling_price' => 3200000,
        ]);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '998877665544',
            'bank_name' => 'BIDV',
            'branch' => 'Cầu Giấy',
            'account_code' => '1121',
        ]);
    }

    /**
     * ADV-00: UNIT RESOLUTION VERIFICATION:
     * When Item has 'unit' as string (e.g. 'Cuộn') and line payload does not pass 'unit',
     * PurchaseReturnResource & PurchaseDiscountResource correctly fallback to Item->unit.
     */
    public function test_purchase_return_and_discount_resolve_unit_from_item_when_line_unit_is_null(): void
    {
        // 1. Purchase Return without 'unit' in line payload
        $returnPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'TLMH-FIX-001',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id, // $this->item has unit='Cuộn'
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                    // 'unit' is omitted here
                ],
            ],
        ];

        $returnRes = $this->postJson('/api/v1/purchase/returns', $returnPayload);
        $returnRes->assertStatus(201);
        $this->assertEquals('Cuộn', $returnRes->json('lines.0.unit'));

        // 2. Purchase Discount without 'unit' in line payload
        $discountPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'GGMH-FIX-001',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                    // 'unit' is omitted here
                ],
            ],
        ];

        $discountRes = $this->postJson('/api/v1/purchase/discounts', $discountPayload);
        $discountRes->assertStatus(201);
        $this->assertEquals('Cuộn', $discountRes->json('lines.0.unit'));
    }

    /**
     * ADV-01: Full lifecycle test for PurchaseReturn (Payable Reduction)
     * create -> post -> verify GL -> reject double post -> reject edit when posted -> unpost -> verify GL voided -> duplicate -> delete
     */
    public function test_purchase_return_full_lifecycle_payable(): void
    {
        // 1. CREATE DRAFT
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'TLMH-TEST-001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'reduce_payable',
            'reason' => 'Xuất trả 5 cuộn thép do sai quy cách',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'unit' => 'Cuộn',
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 5,
                    'unit_price' => 2500000,
                    'amount' => 12500000,
                    'discount_rate' => 5,
                    'discount_amount' => 625000, // 5% discount
                    'tax_rate' => 10,
                    'tax_amount' => 1187500, // 10% of (12,500,000 - 625,000 = 11,875,000)
                    'tax_account' => '1331',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/purchase/returns', $payload);
        $createRes->assertStatus(201);
        $returnId = $createRes->json('id');
        $this->assertEquals(13062500, $createRes->json('total_amount')); // 11,875,000 + 1,187,500 = 13,062,500
        $this->assertEquals('draft', $createRes->json('status'));
        $this->assertFalse((bool) $createRes->json('is_posted'));

        // 2. POST VOUCHER
        $postRes = $this->postJson("/api/v1/purchase/returns/{$returnId}/post");
        $postRes->assertStatus(200);

        $ret = PurchaseReturn::with('journalEntry.lines')->find($returnId);
        $this->assertTrue($ret->is_posted);
        $this->assertEquals('posted', $ret->status);
        $this->assertNotNull($ret->journal_entry_id);

        $je = $ret->journalEntry;
        $this->assertEquals('posted', $je->status);
        $this->assertEquals('purchase_return', $je->voucher_type);

        // Verify GL Double-Entry Lines
        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(13062500, $sumDebit);
        $this->assertEquals(13062500, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'Sum Debit must strictly equal Sum Credit');

        // Check specific accounts
        $debit331 = $je->lines->firstWhere('account_code', '331');
        $this->assertNotNull($debit331);
        $this->assertEquals(13062500, $debit331->debit_amount);

        $credit1561 = $je->lines->firstWhere('account_code', '1561');
        $this->assertNotNull($credit1561);
        $this->assertEquals(11875000, $credit1561->credit_amount); // net of discount

        $credit1331 = $je->lines->firstWhere('account_code', '1331');
        $this->assertNotNull($credit1331);
        $this->assertEquals(1187500, $credit1331->credit_amount);

        // 3. ADVERSARIAL: REJECT RE-POSTING
        $rePostRes = $this->postJson("/api/v1/purchase/returns/{$returnId}/post");
        $rePostRes->assertStatus(400);

        // 4. ADVERSARIAL: REJECT EDITING WHEN POSTED
        $editRes = $this->putJson("/api/v1/purchase/returns/{$returnId}", [
            'reason' => 'Thay đổi lý do khi đang ghi sổ',
        ]);
        $editRes->assertStatus(409);

        // 5. UNPOST VOUCHER
        $unpostRes = $this->postJson("/api/v1/purchase/returns/{$returnId}/unpost");
        $unpostRes->assertStatus(200);

        $ret->refresh();
        $this->assertFalse($ret->is_posted);
        $this->assertEquals('draft', $ret->status);

        // Verify GL Journal Entry is voided
        $je->refresh();
        $this->assertEquals('voided', $je->status);

        // 6. ADVERSARIAL: REJECT UNPOSTING ALREADY UNPOSTED VOUCHER
        $reUnpostRes = $this->postJson("/api/v1/purchase/returns/{$returnId}/unpost");
        $reUnpostRes->assertStatus(400);

        // 7. DUPLICATE VOUCHER
        $dupRes = $this->postJson("/api/v1/purchase/returns/{$returnId}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('id');

        $this->assertNotEquals($returnId, $dupId);
        $this->assertFalse($dupRes->json('is_posted'));
        $this->assertEquals('draft', $dupRes->json('status'));
        $this->assertNotEquals($ret->voucher_number, $dupRes->json('voucher_number'));
        $this->assertEquals(13062500, $dupRes->json('total_amount'));

        $dup = PurchaseReturn::with('lines')->find($dupId);
        $this->assertCount(1, $dup->lines);
        $this->assertNull($dup->journal_entry_id);

        // 8. DELETE VOUCHERS
        $delDupRes = $this->deleteJson("/api/v1/purchase/returns/{$dupId}");
        $delDupRes->assertStatus(200);
        $this->assertDatabaseMissing('purchase_returns', ['id' => $dupId]);
        $this->assertDatabaseMissing('purchase_return_lines', ['purchase_return_id' => $dupId]);

        $delOriginalRes = $this->deleteJson("/api/v1/purchase/returns/{$returnId}");
        $delOriginalRes->assertStatus(200);
        $this->assertDatabaseMissing('purchase_returns', ['id' => $returnId]);
        $this->assertDatabaseMissing('purchase_return_lines', ['purchase_return_id' => $returnId]);
    }

    /**
     * ADV-02: Full lifecycle test for PurchaseReturn with Cash and Bank refunds
     */
    public function test_purchase_return_cash_and_bank_refund_posting(): void
    {
        // 1. CASH REFUND (Nợ 1111 / Có 1561, Có 1331)
        $cashPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'TLMH-CASH-001',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'cash',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'unit' => 'Cuộn',
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '1111',
                    'credit_account' => '1561',
                    'quantity' => 2,
                    'unit_price' => 1000000,
                    'amount' => 2000000,
                    'tax_rate' => 10,
                    'tax_amount' => 200000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $cashRes = $this->postJson('/api/v1/purchase/returns', $cashPayload);
        $cashRes->assertStatus(201);
        $cashId = $cashRes->json('id');
        $this->postJson("/api/v1/purchase/returns/{$cashId}/post")->assertStatus(200);

        $cashRet = PurchaseReturn::with('journalEntry.lines')->find($cashId);
        $cashDebit = $cashRet->journalEntry->lines->firstWhere('account_code', '1111');
        $this->assertNotNull($cashDebit);
        $this->assertEquals(2200000, $cashDebit->debit_amount);

        // 2. BANK REFUND (Nợ 1121 / Có 1561, Có 1331)
        $bankPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'TLMH-BANK-001',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'bank',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'unit' => 'Cuộn',
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '1121',
                    'credit_account' => '1561',
                    'quantity' => 4,
                    'unit_price' => 1000000,
                    'amount' => 4000000,
                    'tax_rate' => 10,
                    'tax_amount' => 400000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $bankRes = $this->postJson('/api/v1/purchase/returns', $bankPayload);
        $bankRes->assertStatus(201);
        $bankId = $bankRes->json('id');
        $this->postJson("/api/v1/purchase/returns/{$bankId}/post")->assertStatus(200);

        $bankRet = PurchaseReturn::with('journalEntry.lines')->find($bankId);
        $bankDebit = $bankRet->journalEntry->lines->firstWhere('account_code', '1121');
        $this->assertNotNull($bankDebit);
        $this->assertEquals(4400000, $bankDebit->debit_amount);
    }

    /**
     * ADV-03: Full lifecycle test for PurchaseDiscount
     * create -> post -> verify GL balance & accounts -> reject invalid states -> unpost -> duplicate -> delete
     */
    public function test_purchase_discount_full_lifecycle(): void
    {
        // 1. CREATE DRAFT
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'GGMH-TEST-001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'reduce_payable',
            'reason' => 'Giảm giá thương mại theo chính sách sản lượng',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'unit' => 'Cuộn',
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 10,
                    'unit_price' => 500000,
                    'amount' => 5000000,
                    'tax_rate' => 10,
                    'tax_amount' => 500000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/purchase/discounts', $payload);
        $createRes->assertStatus(201);
        $discountId = $createRes->json('id');
        $this->assertEquals(5500000, $createRes->json('total_amount'));
        $this->assertEquals('draft', $createRes->json('status'));

        // 2. POST VOUCHER
        $postRes = $this->postJson("/api/v1/purchase/discounts/{$discountId}/post");
        $postRes->assertStatus(200);

        $disc = PurchaseDiscount::with('journalEntry.lines')->find($discountId);
        $this->assertTrue($disc->is_posted);
        $this->assertEquals('posted', $disc->status);
        $this->assertNotNull($disc->journal_entry_id);

        $je = $disc->journalEntry;
        $this->assertEquals('posted', $je->status);
        $this->assertEquals('purchase_discount', $je->voucher_type);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(5500000, $sumDebit);
        $this->assertEquals(5500000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'Sum Debit must strictly equal Sum Credit');

        // Debit 331, Credit 1561, Credit 1331
        $this->assertEquals(5500000, $je->lines->firstWhere('account_code', '331')->debit_amount);
        $this->assertEquals(5000000, $je->lines->firstWhere('account_code', '1561')->credit_amount);
        $this->assertEquals(500000, $je->lines->firstWhere('account_code', '1331')->credit_amount);

        // 3. ADVERSARIAL: REJECT RE-POST & EDIT
        $this->postJson("/api/v1/purchase/discounts/{$discountId}/post")->assertStatus(400);
        $this->putJson("/api/v1/purchase/discounts/{$discountId}", ['reason' => 'Sửa chứng từ đã ghi sổ'])->assertStatus(409);

        // 4. UNPOST
        $this->postJson("/api/v1/purchase/discounts/{$discountId}/unpost")->assertStatus(200);
        $disc->refresh();
        $je->refresh();
        $this->assertFalse($disc->is_posted);
        $this->assertEquals('draft', $disc->status);
        $this->assertEquals('voided', $je->status);

        // 5. DUPLICATE
        $dupRes = $this->postJson("/api/v1/purchase/discounts/{$discountId}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('id');
        $this->assertNotEquals($discountId, $dupId);
        $this->assertFalse($dupRes->json('is_posted'));

        // 6. DELETE
        $this->deleteJson("/api/v1/purchase/discounts/{$dupId}")->assertStatus(200);
        $this->deleteJson("/api/v1/purchase/discounts/{$discountId}")->assertStatus(200);
        $this->assertDatabaseMissing('purchase_discounts', ['id' => $discountId]);
        $this->assertDatabaseMissing('purchase_discounts', ['id' => $dupId]);
    }

    /**
     * ADV-04: Multi-Step Cross-Voucher Reference Traceability:
     * PurchaseOrder -> PurchaseInvoice -> PurchaseReturn & PurchaseDiscount
     */
    public function test_purchase_order_to_invoice_to_return_and_discount_cross_referencing(): void
    {
        // 1. STEP 1: CREATE PURCHASE ORDER (PO)
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'order_number' => 'PO-2026-0088',
            'order_date' => '2026-08-20',
            'delivery_date' => '2026-08-25',
            'sub_total' => 25000000,
            'tax_amount' => 2500000,
            'total_amount' => 27500000,
            'status' => 'confirmed',
            'description' => 'Đơn mua 10 cuộn thép Việt Nhật',
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->item->id,
            'item_code' => $this->item->code,
            'item_name' => $this->item->name,
            'unit' => 'Cuộn',
            'quantity' => 10,
            'unit_price' => 2500000,
            'amount' => 25000000,
            'tax_rate' => 10,
            'tax_amount' => 2500000,
            'total_amount' => 27500000,
        ]);

        $this->assertDatabaseHas('purchase_orders', ['order_number' => 'PO-2026-0088']);

        // 2. STEP 2: CREATE PURCHASE INVOICE REFERENCING PO
        $invoicePayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'HDMH-2026-0088',
            'invoice_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'due_date' => '2026-09-20',
            'payment_method' => 'unpaid',
            'sub_total' => 25000000,
            'tax_amount' => 2500000,
            'total_amount' => 27500000,
            'grand_total' => 27500000,
            'description' => 'Mua 10 cuộn thép theo đơn hàng '.$po->order_number,
            'referenced_vouchers' => [
                [
                    'target_type' => PurchaseOrder::class,
                    'target_id' => $po->id,
                    'voucher_type' => 'Đơn mua hàng',
                    'voucher_number' => $po->order_number,
                    'voucher_date' => '2026-08-20',
                    'total_amount' => 27500000,
                    'description' => 'Đơn đặt hàng mua tham chiếu',
                ],
            ],
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'unit' => 'Cuộn',
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 2500000,
                    'amount' => 25000000,
                    'tax_rate' => 10,
                    'tax_amount' => 2500000,
                    'tax_account' => '1331',
                    'order_id' => $po->id,
                ],
            ],
        ];

        $invRes = $this->postJson('/api/v1/purchase/invoices', $invoicePayload);
        $invRes->assertStatus(201);
        $invoiceId = $invRes->json('id');

        // Post Invoice
        $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post")->assertStatus(200);

        $invoice = PurchaseInvoice::with(['references', 'journalEntry.lines'])->find($invoiceId);
        $this->assertTrue($invoice->is_posted);

        // Verify Reference link between Invoice and PO
        $this->assertCount(1, $invoice->references);
        $this->assertEquals(PurchaseOrder::class, $invoice->references->first()->target_type);
        $this->assertEquals($po->id, $invoice->references->first()->target_id);

        // Verify Reverse Reference on PO
        $this->assertCount(1, $po->referencedBy);
        $this->assertEquals(PurchaseInvoice::class, $po->referencedBy->first()->source_type);
        $this->assertEquals($invoice->id, $po->referencedBy->first()->source_id);

        // 3. STEP 3: CREATE PURCHASE RETURN REFERENCING PURCHASE INVOICE
        $returnPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'TLMH-2026-0088',
            'voucher_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'payment_method' => 'reduce_payable',
            'reference_invoice_id' => $invoice->id,
            'reason' => 'Trả lại 2 cuộn thép bị rỉ sét từ hóa đơn '.$invoice->invoice_number,
            'referenced_vouchers' => [
                [
                    'target_type' => PurchaseInvoice::class,
                    'target_id' => $invoice->id,
                    'voucher_type' => 'Hóa đơn mua hàng',
                    'voucher_number' => $invoice->invoice_number,
                    'voucher_date' => '2026-08-21',
                    'total_amount' => 27500000,
                    'description' => 'Hóa đơn mua hàng gốc',
                ],
            ],
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'unit' => 'Cuộn',
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 2,
                    'unit_price' => 2500000,
                    'amount' => 5000000,
                    'tax_rate' => 10,
                    'tax_amount' => 500000,
                    'tax_account' => '1331',
                    'invoice_number' => $invoice->invoice_number,
                ],
            ],
        ];

        $returnRes = $this->postJson('/api/v1/purchase/returns', $returnPayload);
        $returnRes->assertStatus(201);
        $returnId = $returnRes->json('id');

        // Post Return
        $this->postJson("/api/v1/purchase/returns/{$returnId}/post")->assertStatus(200);

        $ret = PurchaseReturn::with(['references', 'referenceInvoice', 'journalEntry.lines'])->find($returnId);
        $this->assertTrue($ret->is_posted);
        $this->assertEquals($invoice->id, $ret->reference_invoice_id);

        // Verify Return references Invoice
        $this->assertCount(1, $ret->references);
        $this->assertEquals(PurchaseInvoice::class, $ret->references->first()->target_type);
        $this->assertEquals($invoice->id, $ret->references->first()->target_id);

        // 4. STEP 4: CREATE PURCHASE DISCOUNT REFERENCING PURCHASE INVOICE
        $discountPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'GGMH-2026-0088',
            'voucher_date' => '2026-08-22',
            'payment_method' => 'reduce_payable',
            'reference_invoice_id' => $invoice->id,
            'reason' => 'Giảm giá 1,000,000 cho 8 cuộn thép còn lại từ hóa đơn '.$invoice->invoice_number,
            'referenced_vouchers' => [
                [
                    'target_type' => PurchaseInvoice::class,
                    'target_id' => $invoice->id,
                    'voucher_type' => 'Hóa đơn mua hàng',
                    'voucher_number' => $invoice->invoice_number,
                    'voucher_date' => '2026-08-21',
                    'total_amount' => 27500000,
                ],
            ],
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'unit' => 'Cuộn',
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 8,
                    'unit_price' => 125000,
                    'amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $discRes = $this->postJson('/api/v1/purchase/discounts', $discountPayload);
        $discRes->assertStatus(201);
        $discId = $discRes->json('id');

        $this->postJson("/api/v1/purchase/discounts/{$discId}/post")->assertStatus(200);

        // 5. STEP 5: VERIFY MULTI-HOP BIDIRECTIONAL TRACEABILITY
        // Invoice should be referencedBy both PurchaseReturn and PurchaseDiscount
        $invoice->refresh();
        $this->assertCount(2, $invoice->referencedBy);
        $targetTypes = $invoice->referencedBy->pluck('source_type')->toArray();
        $this->assertContains(PurchaseReturn::class, $targetTypes);
        $this->assertContains(PurchaseDiscount::class, $targetTypes);

        // 6. STEP 6: VERIFY VOUCHER REFERENCE SEARCH API
        $searchRes = $this->getJson('/api/v1/voucher-references/search?module_group=purchase&keyword=2026-0088');
        $searchRes->assertStatus(200);
        $foundVouchers = collect($searchRes->json('data'));

        $this->assertTrue($foundVouchers->contains('voucher_number', 'PO-2026-0088'));
        $this->assertTrue($foundVouchers->contains('voucher_number', 'HDMH-2026-0088'));
        $this->assertTrue($foundVouchers->contains('voucher_number', 'TLMH-2026-0088'));
        $this->assertTrue($foundVouchers->contains('voucher_number', 'GGMH-2026-0088'));

        // 7. STEP 7: VERIFY RESOLVE DEFAULTS API
        $resolveRes = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'Trả lại hàng mua',
            'target_voucher_type' => 'Hóa đơn mua hàng',
            'target_id' => $invoice->id,
            'standard' => 'TT200',
        ]);
        $resolveRes->assertStatus(200);
        $this->assertTrue($resolveRes->json('success'));
    }
}
