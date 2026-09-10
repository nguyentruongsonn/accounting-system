<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\JournalEntry;
use App\Models\PurchaseContract;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VoucherReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Công ty Cổ phần MISA Test',
            'tax_code' => '0101243150',
            'address' => 'Tòa nhà MISA, Cầu Giấy, Hà Nội',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH001',
            'name' => 'Công ty TNHH Alpha Tech',
            'address' => 'Hà Nội',
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC001',
            'name' => 'Công ty TNHH Thiết bị Beta',
            'address' => 'Hồ Chí Minh',
        ]);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '19030012345678',
            'bank_name' => 'Techcombank',
            'branch' => 'Chi nhánh Thăng Long',
        ]);

        // 1. Sales Invoice
        $salesInv = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-999',
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-10',
            'description' => 'Bán phần mềm kế toán MISA',
            'sub_total' => 10000000,
            'tax_amount' => 1000000,
            'total_amount' => 11000000,
        ]);
        SalesInvoiceLine::create([
            'sales_invoice_id' => $salesInv->id,
            'debit_account' => '131',
            'credit_account' => '511',
            'quantity' => 1,
            'unit_price' => 10000000,
            'amount' => 10000000,
        ]);

        // 2. Purchase Invoice
        $purchaseInv = PurchaseInvoice::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'HDMH-888',
            'invoice_date' => '2026-08-12',
            'accounting_date' => '2026-08-12',
            'due_date' => '2026-09-12',
            'description' => 'Mua máy chủ Dell',
            'sub_total' => 20000000,
            'tax_amount' => 2000000,
            'total_amount' => 22000000,
        ]);
        PurchaseInvoiceLine::create([
            'purchase_invoice_id' => $purchaseInv->id,
            'debit_account' => '156',
            'credit_account' => '331',
            'quantity' => 1,
            'unit_price' => 20000000,
            'amount' => 20000000,
        ]);

        // 3. Purchase Order
        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_code' => $this->supplier->code,
            'supplier_name' => $this->supplier->name,
            'order_number' => 'PO-777',
            'order_date' => '2026-08-13',
            'description' => 'Đơn mua linh kiện máy tính',
            'total_amount' => 5000000,
        ]);

        // 4. Purchase Contract
        PurchaseContract::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_code' => $this->supplier->code,
            'supplier_name' => $this->supplier->name,
            'contract_number' => 'HDM-666',
            'contract_name' => 'Hợp đồng bảo trì hệ thống mạng',
            'signed_date' => '2026-08-14',
            'contract_value' => 30000000,
        ]);

        // 5. Cash Receipt
        CashReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PT-555',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'contact_id' => $this->customer->id,
            'contact_name' => 'Công ty TNHH Alpha Tech',
            'payer_name' => 'Nguyễn Văn A',
            'reason' => 'Thu tiền khách hàng Alpha Tech',
            'total_amount' => 11000000,
        ]);

        // 6. Cash Payment
        CashPayment::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PC-444',
            'voucher_date' => '2026-08-16',
            'posting_date' => '2026-08-16',
            'contact_id' => $this->supplier->id,
            'contact_name' => 'Công ty TNHH Thiết bị Beta',
            'receiver_name' => 'Trần Thị B',
            'reason' => 'Chi tiền mua thiết bị Beta',
            'total_amount' => 22000000,
        ]);

        // 7. Bank Receipt
        BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BC-333',
            'voucher_date' => '2026-08-17',
            'posting_date' => '2026-08-17',
            'contact_id' => $this->customer->id,
            'contact_name' => 'Công ty TNHH Alpha Tech',
            'description' => 'Thu tiền gửi ngân hàng Alpha Tech',
            'amount' => 50000000,
        ]);

        // 8. Bank Payment
        BankPayment::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-222',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'contact_id' => $this->supplier->id,
            'contact_name' => 'Công ty TNHH Thiết bị Beta',
            'payee_name' => 'Công ty TNHH Thiết bị Beta',
            'description' => 'Ủy nhiệm chi tiền hàng Beta',
            'amount' => 45000000,
        ]);

        // 9. Inventory Receipt
        InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-111',
            'voucher_date' => '2026-08-19',
            'posting_date' => '2026-08-19',
            'contact_id' => $this->supplier->id,
            'contact_name' => 'Công ty TNHH Thiết bị Beta',
            'description' => 'Nhập kho thiết bị tin học',
            'total_amount' => 15000000,
        ]);

        // 10. Inventory Issue
        InventoryIssue::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-100',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'contact_id' => $this->customer->id,
            'contact_name' => 'Công ty TNHH Alpha Tech',
            'description' => 'Xuất kho giao hàng cho Alpha',
            'total_amount' => 8000000,
        ]);

        // 11. Journal Entry
        JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-050',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'description' => 'Trích khấu hao tài sản cố định',
            'total_amount' => 3500000,
        ]);
    }

    public function test_search_all_voucher_references()
    {
        $response = $this->getJson('/api/v1/voucher-references/search');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'real_id',
                        'model',
                        'voucher_type',
                        'posting_date',
                        'voucher_date',
                        'voucher_number',
                        'description',
                        'contact_id',
                        'contact_code',
                        'contact_name',
                        'debit_account',
                        'credit_account',
                        'total_amount',
                    ],
                ],
                'total',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertGreaterThanOrEqual(11, $response->json('total'));
    }

    public function test_search_by_voucher_type_specific()
    {
        // 1. Specific Hóa đơn bán hàng
        $response = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_type&search_value=Hóa đơn bán hàng');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertEquals('Hóa đơn bán hàng', $item['voucher_type']);
        }

        // 2. Specific Phiếu chi
        $responsePC = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_type&search_value=Phiếu chi');
        $responsePC->assertStatus(200);
        $dataPC = $responsePC->json('data');
        $this->assertNotEmpty($dataPC);
        foreach ($dataPC as $item) {
            $this->assertEquals('Phiếu chi', $item['voucher_type']);
        }
    }

    public function test_search_by_voucher_type_group()
    {
        // Group Mua hàng (includes Hóa đơn mua hàng, Đơn mua hàng, Hợp đồng mua)
        $response = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_type&search_value=Mua hàng');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $types = collect($data)->pluck('voucher_type')->unique()->toArray();
        $this->assertContains('Hóa đơn mua hàng', $types);
        $this->assertContains('Đơn mua hàng', $types);
        $this->assertContains('Hợp đồng mua', $types);
        $this->assertNotContains('Hóa đơn bán hàng', $types);

        // Group Quỹ (includes Phiếu thu, Phiếu chi)
        $responseQuy = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_type&search_value=Quỹ');
        $responseQuy->assertStatus(200);
        $dataQuy = $responseQuy->json('data');
        $typesQuy = collect($dataQuy)->pluck('voucher_type')->unique()->toArray();
        $this->assertContains('Phiếu thu', $typesQuy);
        $this->assertContains('Phiếu chi', $typesQuy);
        $this->assertNotContains('Hóa đơn bán hàng', $typesQuy);
    }

    public function test_search_by_contact_code_or_name()
    {
        // 1. By Customer code KH001
        $response = $this->getJson('/api/v1/voucher-references/search?search_by=contact&search_value=KH001');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $matches = str_contains($item['contact_code'] ?? '', 'KH001') ||
                       str_contains($item['contact_name'] ?? '', 'Alpha Tech') ||
                       str_contains((string) ($item['contact_id'] ?? ''), 'KH001');
            $this->assertTrue($matches);
        }

        // 2. By Supplier name "Thiết bị Beta"
        $responseSup = $this->getJson('/api/v1/voucher-references/search?search_by=contact&search_value=Thiết bị Beta');
        $responseSup->assertStatus(200);
        $dataSup = $responseSup->json('data');
        $this->assertNotEmpty($dataSup);
        foreach ($dataSup as $item) {
            $this->assertStringContainsStringIgnoringCase('Beta', $item['contact_name']);
        }
    }

    public function test_search_by_voucher_number()
    {
        $response = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_number&search_value=HDBH-999');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('HDBH-999', $data[0]['voucher_number']);
        $this->assertEquals('Hóa đơn bán hàng', $data[0]['voucher_type']);
        $this->assertEquals(11000000, $data[0]['total_amount']);
    }

    public function test_filter_by_date_range()
    {
        // Date range from 2026-08-10 to 2026-08-12 (only HDBH-999 and HDMH-888)
        $response = $this->getJson('/api/v1/voucher-references/search?from_date=2026-08-10&to_date=2026-08-12');
        $response->assertStatus(200);
        $data = $response->json('data');
        $voucherNumbers = collect($data)->pluck('voucher_number')->toArray();
        $this->assertContains('HDBH-999', $voucherNumbers);
        $this->assertContains('HDMH-888', $voucherNumbers);
        $this->assertNotContains('PT-555', $voucherNumbers);
    }

    public function test_filter_by_keyword()
    {
        $response = $this->getJson('/api/v1/voucher-references/search?keyword=khấu hao');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('PKT-050', $data[0]['voucher_number']);
        $this->assertEquals('Trích khấu hao tài sản cố định', $data[0]['description']);
    }

    public function test_adversarial_invalid_search_by()
    {
        $response = $this->getJson('/api/v1/voucher-references/search?search_by=invalid_mode&search_value=Alpha');
        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'total']);
        $this->assertTrue($response->json('success'));
    }

    public function test_adversarial_empty_and_inverted_dates()
    {
        // Empty date query parameters
        $resp1 = $this->getJson('/api/v1/voucher-references/search?from_date=&to_date=');
        $resp1->assertStatus(200);
        $this->assertTrue($resp1->json('success'));
        $this->assertGreaterThanOrEqual(11, $resp1->json('total'));

        // Inverted date range (from_date > to_date)
        $resp2 = $this->getJson('/api/v1/voucher-references/search?from_date=2026-12-31&to_date=2026-01-01');
        $resp2->assertStatus(200);
        $this->assertEquals(0, $resp2->json('total'));
        $this->assertEmpty($resp2->json('data'));
    }

    public function test_adversarial_special_characters_and_injection_resilience()
    {
        // SQL injection payload in keyword
        $resp1 = $this->getJson('/api/v1/voucher-references/search?keyword='.urlencode("' OR '1'='1"));
        $resp1->assertStatus(200);
        $this->assertTrue($resp1->json('success'));

        // HTML/XSS payload
        $resp2 = $this->getJson('/api/v1/voucher-references/search?keyword='.urlencode('<script>alert("xss")</script>'));
        $resp2->assertStatus(200);
        $this->assertEquals(0, $resp2->json('total'));

        // Extreme special characters and symbols
        $resp3 = $this->getJson('/api/v1/voucher-references/search?keyword='.urlencode('~!@#$%^&*()_+`{}|[]\\:";\'<>?,./'));
        $resp3->assertStatus(200);
        $this->assertEquals(0, $resp3->json('total'));
    }

    public function test_adversarial_unmatched_keywords_and_whitespace()
    {
        // Whitespace only keyword should be trimmed and return all results without crash
        $resp1 = $this->getJson('/api/v1/voucher-references/search?keyword='.urlencode('     '));
        $resp1->assertStatus(200);
        $this->assertGreaterThanOrEqual(11, $resp1->json('total'));

        // Gibberish unmatched keyword
        $resp2 = $this->getJson('/api/v1/voucher-references/search?keyword=gibberish_nonexistent_xyz99999');
        $resp2->assertStatus(200);
        $this->assertEquals(0, $resp2->json('total'));
        $this->assertEmpty($resp2->json('data'));
    }

    public function test_adversarial_nonexistent_contacts_and_vouchers()
    {
        // Nonexistent contact
        $resp1 = $this->getJson('/api/v1/voucher-references/search?search_by=contact&search_value=NONEXISTENT_CONTACT_CODE_404');
        $resp1->assertStatus(200);
        $this->assertTrue($resp1->json('success'));
        $this->assertEquals(0, $resp1->json('total'));

        // Nonexistent voucher number
        $resp2 = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_number&search_value=NO_SUCH_VOUCHER_NUMBER_9999');
        $resp2->assertStatus(200);
        $this->assertTrue($resp2->json('success'));
        $this->assertEquals(0, $resp2->json('total'));
    }

    public function test_adversarial_case_insensitive_matching()
    {
        // Lowercase voucher number matching uppercase in DB
        $resp = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_number&search_value=hdbh-999');
        $resp->assertStatus(200);
        $this->assertEquals(1, $resp->json('total'));
        $this->assertEquals('HDBH-999', $resp->json('data.0.voucher_number'));

        // Lowercase contact name matching mixed-case in DB
        $resp2 = $this->getJson('/api/v1/voucher-references/search?search_by=contact&search_value=alpha tech');
        $resp2->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $resp2->json('total'));
    }

    public function test_search_preserves_source_account_evidence_and_does_not_invent_missing_mappings(): void
    {
        $response = $this->getJson('/api/v1/voucher-references/search');
        $response->assertOk();

        $rows = collect($response->json('data'));

        $salesInvoice = $rows->firstWhere('voucher_number', 'HDBH-999');
        $this->assertSame('131', $salesInvoice['debit_account']);
        $this->assertSame('511', $salesInvoice['credit_account']);

        $purchaseInvoice = $rows->firstWhere('voucher_number', 'HDMH-888');
        $this->assertSame('156', $purchaseInvoice['debit_account']);
        $this->assertSame('331', $purchaseInvoice['credit_account']);

        // These fixtures intentionally have no persisted journal lines. The
        // reference search must not turn historical account defaults into
        // accounting evidence that can be copied into a new voucher.
        foreach (['PT-555', 'PC-444', 'BC-333', 'UNC-222', 'PNK-111', 'PXK-100'] as $voucherNumber) {
            $row = $rows->firstWhere('voucher_number', $voucherNumber);
            $this->assertNotNull($row, "Missing reference row {$voucherNumber}");
            $this->assertNull($row['debit_account'], "Unexpected debit mapping on {$voucherNumber}");
            $this->assertNull($row['credit_account'], "Unexpected credit mapping on {$voucherNumber}");
        }
    }

    public function test_search_preserves_missing_source_fields_without_synthetic_fallbacks(): void
    {
        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'PO-NULL-EVIDENCE',
            'order_date' => '2026-08-22',
            'supplier_id' => null,
            'supplier_code' => null,
            'supplier_name' => null,
            'description' => null,
            'total_amount' => 0,
        ]);

        $response = $this->getJson('/api/v1/voucher-references/search?module_group=purchase&search_by=voucher_number&keyword=PO-NULL-EVIDENCE');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('voucher_number', 'PO-NULL-EVIDENCE');
        $this->assertNotNull($row);
        $this->assertNull($row['description']);
        $this->assertNull($row['contact_id']);
        $this->assertNull($row['contact_code']);
        $this->assertNull($row['contact_name']);
    }
}
