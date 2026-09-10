<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankPaymentLine;
use App\Models\BankReceipt;
use App\Models\ClosingRule;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuote;
use App\Models\SalesQuoteLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VoucherReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MisaMilestone1StandardizationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected FiscalYear $fiscalYear;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bankAccount;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Công ty TNHH MISA M1 Corp',
            'tax_code' => '0109998881',
            'address' => 'Hà Nội, Việt Nam',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH-TEST-01',
            'name' => 'Công ty Cổ phần Khách Hàng Thử Nghiệm',
            'address' => '123 Đường Láng, Hà Nội',
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC-TEST-01',
            'name' => 'Công ty Cung Cấp Linh Kiện Alpha',
            'address' => '456 Cầu Giấy, Hà Nội',
        ]);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '19030099887766',
            'bank_name' => 'Techcombank',
            'branch' => 'Hà Nội',
            'account_code' => '1121',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'SP001',
            'name' => 'Máy in nhiệt MISA POS',
            'unit' => 'Chiếc',
            'cost_price' => 1500000,
            'selling_price' => 2200000,
            'inventory_account' => '1561',
            'cogs_account' => '632',
            'revenue_account' => '511',
        ]);
    }

    /**
     * Test 1: Verify Schema Columns & Tables exist in Database
     */
    public function test_schema_tables_and_columns_exist(): void
    {
        // Tables exist
        $this->assertTrue(Schema::hasTable('sales_quotes'), 'Table sales_quotes should exist');
        $this->assertTrue(Schema::hasTable('sales_quote_lines'), 'Table sales_quote_lines should exist');
        $this->assertTrue(Schema::hasTable('sales_orders'), 'Table sales_orders should exist');
        $this->assertTrue(Schema::hasTable('sales_order_lines'), 'Table sales_order_lines should exist');
        $this->assertTrue(Schema::hasTable('closing_rules'), 'Table closing_rules should exist');

        // Bank Receipts columns
        $this->assertTrue(Schema::hasColumn('bank_receipts', 'voucher_type'));
        $this->assertTrue(Schema::hasColumn('bank_receipts', 'employee_id'));
        $this->assertTrue(Schema::hasColumn('bank_receipts', 'employee_name'));
        $this->assertTrue(Schema::hasColumn('bank_receipts', 'referenced_vouchers'));

        // Bank Payments columns
        $this->assertTrue(Schema::hasColumn('bank_payments', 'voucher_type'));
        $this->assertTrue(Schema::hasColumn('bank_payments', 'employee_id'));
        $this->assertTrue(Schema::hasColumn('bank_payments', 'employee_name'));
        $this->assertTrue(Schema::hasColumn('bank_payments', 'payee_bank_name'));
        $this->assertTrue(Schema::hasColumn('bank_payments', 'payee_branch'));
        $this->assertTrue(Schema::hasColumn('bank_payments', 'fee_bearer'));
        $this->assertTrue(Schema::hasColumn('bank_payments', 'referenced_vouchers'));

        // Bank lines
        $this->assertTrue(Schema::hasColumn('bank_receipt_lines', 'operation'));
        $this->assertTrue(Schema::hasColumn('bank_receipt_lines', 'loan_contract'));
        $this->assertTrue(Schema::hasColumn('bank_receipt_lines', 'line_contact_id'));
        $this->assertTrue(Schema::hasColumn('bank_receipt_lines', 'line_contact_name'));
        $this->assertTrue(Schema::hasColumn('bank_payment_lines', 'operation'));
        $this->assertTrue(Schema::hasColumn('bank_payment_lines', 'loan_contract'));
        $this->assertTrue(Schema::hasColumn('bank_payment_lines', 'line_contact_id'));
        $this->assertTrue(Schema::hasColumn('bank_payment_lines', 'line_contact_name'));

        // Purchase Invoices
        $this->assertTrue(Schema::hasColumn('purchase_invoices', 'referenced_vouchers'));
        $this->assertTrue(Schema::hasColumn('purchase_invoices', 'employee_id'));
        $this->assertTrue(Schema::hasColumn('purchase_invoices', 'employee_name'));
        $this->assertTrue(Schema::hasColumn('purchase_invoice_lines', 'warehouse_id'));
        $this->assertTrue(Schema::hasColumn('purchase_invoice_lines', 'warehouse_code'));
        $this->assertTrue(Schema::hasColumn('purchase_invoice_lines', 'order_id'));
        $this->assertTrue(Schema::hasColumn('purchase_invoice_lines', 'contract_id'));

        // Sales Invoices
        $this->assertTrue(Schema::hasColumn('sales_invoices', 'voucher_type'));
        $this->assertTrue(Schema::hasColumn('sales_invoices', 'payment_method'));
        $this->assertTrue(Schema::hasColumn('sales_invoices', 'delivery_voucher_number'));
        $this->assertTrue(Schema::hasColumn('sales_invoices', 'invoice_symbol'));
        $this->assertTrue(Schema::hasColumn('sales_invoices', 'invoice_code'));
        $this->assertTrue(Schema::hasColumn('sales_invoices', 'referenced_vouchers'));
        $this->assertTrue(Schema::hasColumn('sales_invoice_lines', 'unit'));
        $this->assertTrue(Schema::hasColumn('sales_invoice_lines', 'warehouse_id'));
        $this->assertTrue(Schema::hasColumn('sales_invoice_lines', 'warehouse_code'));
        $this->assertTrue(Schema::hasColumn('sales_invoice_lines', 'cogs_debit_account'));
        $this->assertTrue(Schema::hasColumn('sales_invoice_lines', 'cogs_credit_account'));
        $this->assertTrue(Schema::hasColumn('sales_invoice_lines', 'cogs_unit_price'));
        $this->assertTrue(Schema::hasColumn('sales_invoice_lines', 'cogs_amount'));

        // Inventory
        $this->assertTrue(Schema::hasColumn('inventory_receipts', 'voucher_type'));
        $this->assertTrue(Schema::hasColumn('inventory_receipts', 'receiver_address'));
        $this->assertTrue(Schema::hasColumn('inventory_receipts', 'referenced_vouchers'));
        $this->assertTrue(Schema::hasColumn('inventory_issues', 'voucher_type'));
        $this->assertTrue(Schema::hasColumn('inventory_issues', 'receiver_address'));
        $this->assertTrue(Schema::hasColumn('inventory_issues', 'referenced_vouchers'));
        $this->assertTrue(Schema::hasColumn('inventory_receipt_lines', 'warehouse_code'));
        $this->assertTrue(Schema::hasColumn('inventory_receipt_lines', 'unit'));
        $this->assertTrue(Schema::hasColumn('inventory_receipt_lines', 'description'));
        $this->assertTrue(Schema::hasColumn('inventory_issue_lines', 'warehouse_code'));
        $this->assertTrue(Schema::hasColumn('inventory_issue_lines', 'unit'));
        $this->assertTrue(Schema::hasColumn('inventory_issue_lines', 'description'));

        // Journal Entries
        $this->assertTrue(Schema::hasColumn('journal_entries', 'referenced_vouchers'));
        $this->assertTrue(Schema::hasColumn('journal_entries', 'attached_docs'));
        $this->assertTrue(Schema::hasColumn('journal_entry_lines', 'contact_type'));
        $this->assertTrue(Schema::hasColumn('journal_entry_lines', 'contact_id'));
        $this->assertTrue(Schema::hasColumn('journal_entry_lines', 'contact_name'));
        $this->assertTrue(Schema::hasColumn('journal_entry_lines', 'cost_item_code'));
        $this->assertTrue(Schema::hasColumn('journal_entry_lines', 'cost_object_code'));
        $this->assertTrue(Schema::hasColumn('journal_entry_lines', 'bank_account_id'));
    }

    /**
     * Test 2: Verify ClosingRule Seed Data
     */
    public function test_closing_rules_seed_data_loaded(): void
    {
        $this->assertGreaterThanOrEqual(11, ClosingRule::count());

        $revRule = ClosingRule::where('rule_code', 'KC_REV_511_911')->first();
        $this->assertNotNull($revRule);
        $this->assertEquals('511', $revRule->debit_account);
        $this->assertEquals('911', $revRule->credit_account);
        $this->assertEquals('revenue', $revRule->rule_type);

        $expRule = ClosingRule::where('rule_code', 'KC_EXP_632_911')->first();
        $this->assertNotNull($expRule);
        $this->assertEquals('911', $expRule->debit_account);
        $this->assertEquals('632', $expRule->credit_account);
        $this->assertEquals('expense', $expRule->rule_type);

        $profitRule = ClosingRule::where('rule_code', 'KC_RES_PROFIT_911_4212')->first();
        $this->assertNotNull($profitRule);
        $this->assertEquals('911', $profitRule->debit_account);
        $this->assertEquals('4212', $profitRule->credit_account);

        $lossRule = ClosingRule::where('rule_code', 'KC_RES_LOSS_4212_911')->first();
        $this->assertNotNull($lossRule);
        $this->assertEquals('4212', $lossRule->debit_account);
        $this->assertEquals('911', $lossRule->credit_account);
    }

    /**
     * Test 3: Verify SalesQuote & SalesOrder Models CRUD & Relations
     */
    public function test_sales_quotes_and_orders_models_functionality(): void
    {
        // 1. Sales Quote
        $quote = SalesQuote::create([
            'company_id' => $this->company->id,
            'quote_number' => 'BG-2026-0001',
            'quote_date' => '2026-08-20',
            'expiry_date' => '2026-09-20',
            'customer_id' => $this->customer->id,
            'customer_code' => $this->customer->code,
            'customer_name' => $this->customer->name,
            'sub_total' => 2200000,
            'vat_amount' => 220000,
            'total_amount' => 2420000,
            'grand_total' => 2420000,
            'status' => 'approved',
            'referenced_vouchers' => [
                [
                    'voucher_type' => 'Yêu cầu báo giá',
                    'voucher_number' => 'YCBG001',
                    'voucher_date' => '2026-08-19',
                    'total_amount' => 2420000,
                ],
            ],
        ]);

        $this->assertNotNull($quote->id);
        $this->assertIsArray($quote->referenced_vouchers);
        $this->assertEquals('BG-2026-0001', $quote->quote_number);

        $quoteLine = SalesQuoteLine::create([
            'sales_quote_id' => $quote->id,
            'item_id' => $this->item->id,
            'item_code' => $this->item->code,
            'item_name' => $this->item->name,
            'unit' => 'Chiếc',
            'quantity' => 1,
            'unit_price' => 2200000,
            'amount' => 2200000,
            'tax_rate' => 10,
            'tax_amount' => 220000,
            'total_amount' => 2420000,
        ]);

        $this->assertCount(1, $quote->lines);
        $this->assertEquals($quote->id, $quoteLine->quote->id);

        // 2. Sales Order
        $order = SalesOrder::create([
            'company_id' => $this->company->id,
            'sales_quote_id' => $quote->id,
            'quote_id' => $quote->id,
            'order_number' => 'ĐH-2026-0001',
            'order_date' => '2026-08-20',
            'delivery_date' => '2026-08-25',
            'customer_id' => $this->customer->id,
            'customer_code' => $this->customer->code,
            'customer_name' => $this->customer->name,
            'sub_total' => 2200000,
            'vat_amount' => 220000,
            'total_amount' => 2420000,
            'grand_total' => 2420000,
            'status' => 'confirmed',
            'delivery_status' => 'not_delivered',
            'invoice_status' => 'not_invoiced',
        ]);

        $this->assertNotNull($order->id);
        $this->assertEquals($quote->id, $order->quote->id);

        $orderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'item_id' => $this->item->id,
            'item_code' => $this->item->code,
            'item_name' => $this->item->name,
            'unit' => 'Chiếc',
            'quantity' => 1,
            'delivered_quantity' => 0,
            'invoiced_quantity' => 0,
            'unit_price' => 2200000,
            'amount' => 2200000,
            'tax_rate' => 10,
            'tax_amount' => 220000,
            'total_amount' => 2420000,
        ]);

        $this->assertCount(1, $order->lines);
        $this->assertEquals($order->id, $orderLine->order->id);
    }

    /**
     * Test 4: Verify syncReferences() on HasVoucherReferences Trait across Vouchers
     */
    public function test_has_voucher_references_trait_sync_and_lookup(): void
    {
        // 1. Create a Sales Invoice
        $salesInvoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'invoice_number' => 'HDBH-2026-0001',
            'invoice_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'due_date' => '2026-09-20',
            'sub_total' => 5000000,
            'tax_amount' => 500000,
            'total_amount' => 5500000,
            'status' => 'posted',
            'is_posted' => true,
        ]);

        // 2. Create a Bank Receipt referencing the Sales Invoice
        $bankReceipt = BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_type' => 'Thu tiền gửi khách hàng',
            'contact_type' => 'customer',
            'contact_id' => $this->customer->id,
            'contact_name' => $this->customer->name,
            'voucher_number' => 'BC-2026-0001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'payer_name' => $this->customer->name,
            'amount' => 5500000,
            'is_posted' => true,
            'referenced_vouchers' => [
                [
                    'target_type' => SalesInvoice::class,
                    'target_id' => $salesInvoice->id,
                    'voucher_type' => 'Hóa đơn bán hàng',
                    'voucher_number' => $salesInvoice->invoice_number,
                    'voucher_date' => '2026-08-20',
                    'total_amount' => 5500000,
                    'description' => 'Thu tiền gửi thanh toán hóa đơn '.$salesInvoice->invoice_number,
                ],
            ],
        ]);

        // Call syncReferences
        $bankReceipt->syncReferences($bankReceipt->referenced_vouchers);

        // Check Polymorphic VoucherReference creation
        $this->assertCount(1, $bankReceipt->references);
        $ref = $bankReceipt->references->first();
        $this->assertEquals(SalesInvoice::class, $ref->target_type);
        $this->assertEquals($salesInvoice->id, $ref->target_id);
        $this->assertEquals('HDBH-2026-0001', $ref->target_voucher_number);
        $this->assertEquals(5500000, $ref->target_total_amount);

        // Check Reverse Traceability (referencedBy) on SalesInvoice
        $this->assertCount(1, $salesInvoice->referencedBy);
        $reverseRef = $salesInvoice->referencedBy->first();
        $this->assertEquals(BankReceipt::class, $reverseRef->source_type);
        $this->assertEquals($bankReceipt->id, $reverseRef->source_id);
    }

    /**
     * Test 5: Verify BankPayment, PurchaseInvoice, Inventory, and JournalEntry models
     */
    public function test_all_voucher_models_support_m1_attributes(): void
    {
        // 1. Bank Payment
        $bankPayment = BankPayment::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_type' => 'Ủy nhiệm chi trả NCC',
            'contact_type' => 'supplier',
            'contact_id' => $this->supplier->id,
            'contact_name' => $this->supplier->name,
            'voucher_number' => 'BN-2026-0001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'payee_name' => $this->supplier->name,
            'payee_bank_name' => 'Vietcombank',
            'payee_branch' => 'Thăng Long',
            'fee_bearer' => 'buyer',
            'amount' => 3000000,
            'is_posted' => true,
            'referenced_vouchers' => [],
        ]);

        $bankPaymentLine = BankPaymentLine::create([
            'bank_payment_id' => $bankPayment->id,
            'description' => 'Chi tiền gửi cho nhà cung cấp',
            'debit_account' => '331',
            'credit_account' => '1121',
            'amount' => 3000000,
            'operation' => 'Trả tiền NCC',
            'loan_contract' => 'HD01',
            'line_contact_id' => $this->supplier->id,
            'line_contact_name' => $this->supplier->name,
        ]);

        $this->assertCount(1, $bankPayment->lines);
        $this->assertEquals('Vietcombank', $bankPayment->payee_bank_name);
        $this->assertEquals('buyer', $bankPayment->fee_bearer);

        // 2. Inventory Receipt & Issue
        $invReceipt = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_type' => 'Nhập kho mua hàng',
            'contact_type' => 'supplier',
            'contact_id' => $this->supplier->id,
            'contact_name' => $this->supplier->name,
            'voucher_number' => 'NK-2026-0001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'total_amount' => 15000000,
            'is_posted' => true,
        ]);

        $invReceiptLine = InventoryReceiptLine::create([
            'inventory_receipt_id' => $invReceipt->id,
            'item_id' => $this->item->id,
            'unit' => 'Chiếc',
            'warehouse_code' => 'KHO_TONG',
            'description' => 'Nhập kho máy in POS',
            'quantity' => 10,
            'unit_price' => 1500000,
            'amount' => 15000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        $this->assertCount(1, $invReceipt->lines);
        $this->assertEquals('KHO_TONG', $invReceiptLine->warehouse_code);

        // 3. Journal Entry
        $journal = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'Chứng từ kết chuyển',
            'voucher_number' => 'PKC-2026-0001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'description' => 'Kết chuyển doanh thu tháng 8/2026',
            'total_amount' => 50000000,
            'status' => 'posted',
            'attached_docs' => 'Bảng kê kết chuyển',
            'currency' => 'VND',
            'exchange_rate' => 1,
        ]);

        $journalLine = JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_code' => '511',
            'description' => 'Kết chuyển doanh thu sang 911',
            'debit_amount' => 50000000,
            'credit_amount' => 0,
            'cost_item_code' => 'CP_KD',
        ]);

        $this->assertCount(1, $journal->lines);
        $this->assertEquals('CP_KD', $journalLine->cost_item_code);
    }

    /**
     * Test 6: Verify Voucher Reference Search Controller returns SalesQuote and SalesOrder
     */
    public function test_voucher_reference_search_controller_supports_sales_quotes_and_orders(): void
    {
        Sanctum::actingAs($this->user);

        // Create Sales Quote
        $quote = SalesQuote::create([
            'company_id' => $this->company->id,
            'quote_number' => 'BG-TEST-999',
            'quote_date' => '2026-08-20',
            'customer_id' => $this->customer->id,
            'customer_code' => $this->customer->code,
            'customer_name' => $this->customer->name,
            'total_amount' => 12000000,
        ]);

        // Create Sales Order
        $order = SalesOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'DDH-TEST-999',
            'order_date' => '2026-08-20',
            'customer_id' => $this->customer->id,
            'customer_code' => $this->customer->code,
            'customer_name' => $this->customer->name,
            'total_amount' => 15000000,
        ]);

        $response = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_type&search_value=Tất cả');
        $response->assertStatus(200);
        $data = $response->json('data');

        $foundQuote = collect($data)->firstWhere('voucher_number', 'BG-TEST-999');
        $this->assertNotNull($foundQuote, 'Sales Quote should be returned in voucher reference search');
        $this->assertEquals('SalesQuote', $foundQuote['model']);
        $this->assertEquals(12000000, $foundQuote['total_amount']);

        $foundOrder = collect($data)->firstWhere('voucher_number', 'DDH-TEST-999');
        $this->assertNotNull($foundOrder, 'Sales Order should be returned in voucher reference search');
        $this->assertEquals('SalesOrder', $foundOrder['model']);
        $this->assertEquals(15000000, $foundOrder['total_amount']);
    }
}
