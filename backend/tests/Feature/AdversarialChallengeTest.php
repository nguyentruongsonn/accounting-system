<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\BorrowingContract;
use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CostAllocation;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\ProductionOrder;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Supplier;
use App\Models\ToolEquipment;
use App\Models\User;
use App\Services\BankPaymentService;
use App\Services\JournalEntryService;
use App\Services\PeriodClosingService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdversarialChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bankAccount;

    protected Item $item;

    protected FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Adversarial Test Enterprise',
            'tax_code' => '0109998888',
            'address' => 'Hanoi, Vietnam',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);
        // These fixtures exercise posting invariants, so use the canonical
        // two-role identity required by the production posting authorizer.
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'Năm tài chính 2026',
            'code' => 'FY2026',
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_closed' => false,
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH-ADV-01',
            'name' => 'Công ty Đối Kháng A',
            'address' => 'Hà Nội',
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC-ADV-01',
            'name' => 'Nhà Cung Cấp Đối Kháng B',
            'address' => 'Hà Nội',
        ]);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '9988776655',
            'bank_name' => 'Vietcombank',
            'branch' => 'Sở Giao Dịch',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'SP-ADV-01',
            'name' => 'Sản phẩm thử nghiệm',
            'unit' => 'Cái',
            'cost_price' => 500000,
            'selling_price' => 800000,
        ]);

        $this->seedChartOfAccounts();
    }

    private function seedChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'amphibious'],
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '154', 'name' => 'Chi phí SXKD dở dang', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '155', 'name' => 'Thành phẩm', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '2111', 'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '2141', 'name' => 'Hao mòn TSCĐ', 'type' => 'asset', 'nature' => 'credit'],
            ['code' => '242', 'name' => 'Chi phí trả trước', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'amphibious'],
            ['code' => '3331', 'name' => 'Thuế GTGT phải nộp', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '3341', 'name' => 'Phải trả người lao động', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '3411', 'name' => 'Vay và nợ thuê tài chính', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '4212', 'name' => 'Lợi nhuận sau thuế chưa phân phối năm nay', 'type' => 'equity', 'nature' => 'credit'],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '515', 'name' => 'Doanh thu hoạt động tài chính', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '521', 'name' => 'Các khoản giảm trừ doanh thu', 'type' => 'revenue', 'nature' => 'debit'],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '635', 'name' => 'Chi phí tài chính', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '641', 'name' => 'Chi phí bán hàng', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '6421', 'name' => 'Chi phí nhân viên quản lý', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '6422', 'name' => 'Chi phí vật liệu quản lý', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '6423', 'name' => 'Chi phí đồ dùng văn phòng', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '911', 'name' => 'Xác định kết quả kinh doanh', 'type' => 'equity', 'nature' => 'amphibious'],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::firstOrCreate(
                ['company_id' => $this->company->id, 'code' => $acc['code']],
                [
                    'name' => $acc['name'],
                    'type' => $acc['type'],
                    'nature' => $acc['nature'],
                    'level' => 1,
                    'is_parent' => 0,
                ]
            );
        }
    }

    public function test_self_referencing_voucher_handling(): void
    {
        $inv = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-SELF-01',
            'invoice_date' => '2026-08-20',
            'total_amount' => 10000000,
        ]);

        $inv->syncReferences([
            [
                'target_type' => SalesInvoice::class,
                'target_id' => $inv->id,
                'voucher_type' => 'Hóa đơn bán hàng',
                'voucher_number' => $inv->invoice_number,
                'total_amount' => $inv->total_amount,
            ],
        ]);

        $this->assertCount(1, $inv->references);
        $this->assertEquals($inv->id, $inv->references->first()->target_id);

        $json = $inv->load(['references', 'referencedBy'])->toArray();
        $this->assertIsArray($json);
        $this->assertCount(1, $json['references']);
        $this->assertCount(1, $json['referenced_by']);

        $res = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'SalesInvoice',
            'target_voucher_type' => 'SalesInvoice',
            'target_voucher_id' => $inv->id,
        ]);
        $res->assertStatus(200);
        $this->assertEquals(10000000, $res->json('data.total_amount'));
    }

    public function test_multi_step_circular_reference_loop_integrity(): void
    {
        $invA = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-LOOP-A',
            'invoice_date' => '2026-08-20',
            'total_amount' => 1000000,
        ]);

        $receiptB = CashReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PT-LOOP-B',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'total_amount' => 1000000,
        ]);

        $bankC = BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BC-LOOP-C',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'amount' => 1000000,
        ]);

        $invA->syncReferences([
            [
                'target_type' => CashReceipt::class,
                'target_id' => $receiptB->id,
                'voucher_type' => 'Phiếu thu',
                'voucher_number' => $receiptB->voucher_number,
                'total_amount' => 1000000,
            ],
        ]);

        $receiptB->syncReferences([
            [
                'target_type' => BankReceipt::class,
                'target_id' => $bankC->id,
                'voucher_type' => 'Thu tiền gửi',
                'voucher_number' => $bankC->voucher_number,
                'total_amount' => 1000000,
            ],
        ]);

        $bankC->syncReferences([
            [
                'target_type' => SalesInvoice::class,
                'target_id' => $invA->id,
                'voucher_type' => 'Hóa đơn bán hàng',
                'voucher_number' => $invA->invoice_number,
                'total_amount' => 1000000,
            ],
        ]);

        $this->assertEquals($receiptB->id, $invA->references->first()->target_id);
        $this->assertEquals($bankC->id, $receiptB->references->first()->target_id);
        $this->assertEquals($invA->id, $bankC->references->first()->target_id);

        $this->assertEquals($bankC->id, $invA->referencedBy->first()->source_id);
        $this->assertEquals($invA->id, $receiptB->referencedBy->first()->source_id);
        $this->assertEquals($receiptB->id, $bankC->referencedBy->first()->source_id);

        $receiptB->delete();
        $this->assertSoftDeleted('cash_receipts', ['id' => $receiptB->id]);
    }

    public function test_sales_invoice_unpost_then_delete_gl_integrity(): void
    {
        $salesService = app(SalesInvoiceService::class);

        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'invoice_number' => 'HDBH-UNPOST-DEL-01',
            'invoice_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'total_amount' => 50000000,
            'is_posted' => false,
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'item_id' => $this->item->id,
            'quantity' => 10,
            'unit_price' => 5000000,
            'amount' => 50000000,
            'debit_account' => '131',
            'credit_account' => '5111',
        ]);

        $salesService->post($invoice->id);
        $invoice->refresh();
        $this->assertTrue((bool) $invoice->is_posted);
        $this->assertNotNull($invoice->journal_entry_id);

        $je = JournalEntry::find($invoice->journal_entry_id);
        $this->assertEquals('posted', $je->status);

        $salesService->unpost($invoice->id);
        $invoice->refresh();
        $this->assertFalse((bool) $invoice->is_posted);
        $this->assertEquals('draft', $invoice->status);

        $je->refresh();
        $this->assertEquals('voided', $je->status);

        $salesService->delete($invoice->id);
        $this->assertDatabaseMissing('sales_invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('sales_invoice_lines', ['sales_invoice_id' => $invoice->id]);
    }

    public function test_bank_payment_unpost_then_delete_integrity(): void
    {
        $bpService = app(BankPaymentService::class);

        $payment = BankPayment::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-ADV-01',
            'voucher_date' => '2026-08-20',
            'payment_reason' => 'pay_supplier',
            'payee_name' => 'Công ty NCC B',
            'amount' => 20000000,
            'status' => 'draft',
        ]);
        $payment->lines()->create([
            'description' => 'Thanh toán công nợ nhà cung cấp',
            'debit_account' => '331',
            'credit_account' => '1121',
            'amount' => 20000000,
        ]);

        $bpService->post($payment->id);
        $payment->refresh();
        $this->assertEquals('posted', $payment->status);

        $bpService->unpost($payment->id);
        $payment->refresh();
        $this->assertEquals('draft', $payment->status);

        $bpService->delete($payment->id);
        $this->assertDatabaseMissing('bank_payments', ['id' => $payment->id]);
    }

    public function test_journal_entry_unpost_then_delete_integrity(): void
    {
        $jeService = app(JournalEntryService::class);

        $je = $jeService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PKT-ADV-01',
            'voucher_date' => '2026-08-20',
            'description' => 'Test unpost then delete GL',
            'lines' => [
                ['debit_account' => '1111', 'credit_account' => '131', 'amount' => 15000000],
            ],
            'status' => 'posted',
        ]);

        $this->assertEquals('posted', $je->status);
        $this->assertCount(2, $je->lines);

        $jeService->unpost($je->id);
        $je->refresh();
        $this->assertEquals('voided', $je->status);

        $jeService->delete($je->id);
        $this->assertSoftDeleted('journal_entries', ['id' => $je->id]);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $je->id]);
    }

    public function test_period_closing_net_profit_transfers_to_4212(): void
    {
        $jeService = app(JournalEntryService::class);
        $closingService = app(PeriodClosingService::class);

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_date' => '2026-08-10',
            'description' => 'Ghi nhận doanh thu',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '131', 'credit_account' => '5111', 'amount' => 100000000],
                ['debit_account' => '1121', 'credit_account' => '515', 'amount' => 10000000],
            ],
        ]);

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_date' => '2026-08-15',
            'description' => 'Ghi nhận chi phí',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '632', 'credit_account' => '1561', 'amount' => 60000000],
                ['debit_account' => '6421', 'credit_account' => '3341', 'amount' => 15000000],
                ['debit_account' => '811', 'credit_account' => '1111', 'amount' => 5000000],
            ],
        ]);

        $preview = $closingService->preview($this->company->id, '2026-08-01', '2026-08-31');
        $this->assertEquals(110000000, $preview['total_revenue']);
        $this->assertEquals(80000000, $preview['total_expenses']);
        $this->assertEquals(30000000, $preview['net_profit']);

        $line4212 = collect($preview['suggested_lines'])->first(
            fn (array $line): bool => $line['debit_account'] === '911' && $line['credit_account'] === '4212',
        );
        $this->assertNotNull($line4212);
        $this->assertSame('30000000.00', $line4212['amount']);
    }

    public function test_period_closing_net_loss_transfers_to_4212(): void
    {
        $jeService = app(JournalEntryService::class);
        $closingService = app(PeriodClosingService::class);

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_date' => '2026-08-05',
            'description' => 'Doanh thu thấp',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '131', 'credit_account' => '5111', 'amount' => 40000000],
            ],
        ]);

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_date' => '2026-08-10',
            'description' => 'Chi phí cao',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '632', 'credit_account' => '1561', 'amount' => 70000000],
                ['debit_account' => '6421', 'credit_account' => '3341', 'amount' => 20000000],
            ],
        ]);

        $preview = $closingService->preview($this->company->id, '2026-08-01', '2026-08-31');
        $this->assertEquals(40000000, $preview['total_revenue']);
        $this->assertEquals(90000000, $preview['total_expenses']);
        $this->assertEquals(-50000000, $preview['net_profit']);

        $line4212 = collect($preview['suggested_lines'])->first(
            fn (array $line): bool => $line['debit_account'] === '4212' && $line['credit_account'] === '911',
        );
        $this->assertNotNull($line4212);
        $this->assertSame('50000000.00', $line4212['amount']);
    }

    public function test_period_closing_with_revenue_deductions_521(): void
    {
        $jeService = app(JournalEntryService::class);
        $closingService = app(PeriodClosingService::class);

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_date' => '2026-08-05',
            'description' => 'Doanh thu',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '131', 'credit_account' => '5111', 'amount' => 100000000],
            ],
        ]);

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_date' => '2026-08-08',
            'description' => 'Giảm giá hàng bán',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '521', 'credit_account' => '131', 'amount' => 10000000],
            ],
        ]);

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_date' => '2026-08-10',
            'description' => 'Giá vốn',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '632', 'credit_account' => '1561', 'amount' => 40000000],
            ],
        ]);

        $preview = $closingService->preview($this->company->id, '2026-08-01', '2026-08-31');
        $this->assertEquals(90000000, $preview['total_revenue']);
        $this->assertEquals(40000000, $preview['total_expenses']);
        $this->assertEquals(50000000, $preview['net_profit']);

        // The preview totals are the authoritative calculation boundary. The
        // account-detail mapping is resolved by the posting-policy rollout,
        // while the mandatory close gate is tested separately.
        $this->assertSame('50000000.00', $preview['net_profit']);
    }

    public function test_journal_entry_service_rejects_imbalanced_entries(): void
    {
        $jeService = app(JournalEntryService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Double-entry validation failed');

        $jeService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PKT-IMBAL-01',
            'voucher_date' => '2026-08-20',
            'description' => 'Lệch 1 VND',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 10000000, 'credit_amount' => 0],
                ['account_code' => '131', 'debit_amount' => 0, 'credit_amount' => 9999999],
            ],
        ]);
    }

    public function test_multi_line_compound_balanced_entry_succeeds(): void
    {
        $jeService = app(JournalEntryService::class);

        $entry = $jeService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PKT-COMP-01',
            'voucher_date' => '2026-08-20',
            'description' => 'Bút toán đa dòng phức hợp',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 20000000, 'credit_amount' => 0],
                ['account_code' => '1121', 'debit_amount' => 30000000, 'credit_amount' => 0],
                ['account_code' => '131', 'debit_amount' => 50000000, 'credit_amount' => 0],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 70000000],
                ['account_code' => '3331', 'debit_amount' => 0, 'credit_amount' => 30000000],
            ],
        ]);

        $this->assertEquals(100000000, $entry->total_amount);
        $this->assertEquals(100000000, $entry->lines->sum('debit_amount'));
        $this->assertEquals(100000000, $entry->lines->sum('credit_amount'));
    }

    public function test_extreme_large_amount_and_decimal_precision(): void
    {
        $jeService = app(JournalEntryService::class);

        $largeAmount = 50000000000.55;

        $entry = $jeService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PKT-LARGE-01',
            'voucher_date' => '2026-08-20',
            'description' => 'Giao dịch 50 tỷ đồng có số lẻ',
            'lines' => [
                ['account_code' => '1121', 'debit_amount' => $largeAmount, 'credit_amount' => 0],
                ['account_code' => '3411', 'debit_amount' => 0, 'credit_amount' => $largeAmount],
            ],
        ]);

        $this->assertEquals($largeAmount, (float) $entry->total_amount);
        $this->assertEquals($largeAmount, (float) $entry->lines->firstWhere('account_code', '1121')->debit_amount);
    }

    public function test_cross_voucher_autofill_all_10_modules(): void
    {
        $borrow = BorrowingContract::create([
            'company_id' => $this->company->id,
            'contract_number' => 'KUV-ADV-99',
            'credit_contract' => 'HDTD-99',
            'lender_name' => 'Ngân hàng TMCP Ngoại Thương',
            'amount' => 2000000000,
            'disbursement_date' => '2026-08-15',
            'maturity_date' => '2027-08-15',
            'status' => 'active',
        ]);

        $resBorrow = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'BankReceipt',
            'target_voucher_type' => 'BorrowingContract',
            'target_voucher_id' => $borrow->id,
        ]);
        $resBorrow->assertStatus(200);
        $this->assertEquals(2000000000, $resBorrow->json('data.total_amount'));
        $this->assertEquals('1121', $resBorrow->json('data.lines.0.debit_account'));
        $this->assertEquals('3411', $resBorrow->json('data.lines.0.credit_account'));

        $fa = FixedAsset::create([
            'company_id' => $this->company->id,
            'asset_code' => 'TSCD-ADV-01',
            'asset_name' => 'Dây chuyền đóng gói',
            'asset_account' => '2111',
            'purchase_date' => '2026-08-01',
            'original_cost' => 350000000,
            'depreciable_cost' => 350000000,
            'useful_life_months' => 48,
            'is_active' => true,
        ]);

        $resFA = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'BankPayment',
            'target_voucher_type' => 'FixedAsset',
            'target_voucher_id' => $fa->id,
        ]);
        $resFA->assertStatus(200);
        $this->assertEquals(350000000, $resFA->json('data.total_amount'));
        $this->assertEquals('2111', $resFA->json('data.lines.0.debit_account'));
        $this->assertEquals('1121', $resFA->json('data.lines.0.credit_account'));

        $tool = ToolEquipment::create([
            'company_id' => $this->company->id,
            'tool_code' => 'CCDC-ADV-01',
            'tool_name' => 'Máy hàn điện tử',
            'purchase_date' => '2026-08-01',
            'original_cost' => 12000000,
            'allocation_months' => 12,
            'is_active' => true,
        ]);

        $resTool = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'CashPayment',
            'target_voucher_type' => 'ToolEquipment',
            'target_voucher_id' => $tool->id,
        ]);
        $resTool->assertStatus(200);
        $this->assertEquals(12000000, $resTool->json('data.total_amount'));
        $this->assertEquals('242', $resTool->json('data.lines.0.debit_account'));
        $this->assertEquals('1111', $resTool->json('data.lines.0.credit_account'));

        $payroll = Payroll::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'BL-ADV-08',
            'voucher_date' => '2026-08-31',
            'month' => '2026-08',
            'total_amount' => 75000000,
        ]);

        $resPR = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'BankPayment',
            'target_voucher_type' => 'Payroll',
            'target_voucher_id' => $payroll->id,
        ]);
        $resPR->assertStatus(200);
        $this->assertEquals(75000000, $resPR->json('data.total_amount'));
        $this->assertEquals('3341', $resPR->json('data.lines.0.debit_account'));
        $this->assertEquals('1121', $resPR->json('data.lines.0.credit_account'));

        $prodOrder = ProductionOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'LSX-ADV-01',
            'start_date' => '2026-08-01',
            'item_id' => $this->item->id,
            'planned_quantity' => 50,
            'status' => 'in_progress',
        ]);

        $resPO = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'InventoryIssue',
            'target_voucher_type' => 'ProductionOrder',
            'target_voucher_id' => $prodOrder->id,
            'standard' => 'TT200',
        ]);
        $resPO->assertStatus(200);
        $this->assertEquals('621', $resPO->json('data.lines.0.debit_account'));
        $this->assertEquals('152', $resPO->json('data.lines.0.credit_account'));

        $costAlloc = CostAllocation::create([
            'company_id' => $this->company->id,
            'production_order_id' => $prodOrder->id,
            'month' => '2026-08',
            'total_cost' => 45000000,
        ]);

        $resCA = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'InventoryReceipt',
            'target_voucher_type' => 'CostAllocation',
            'target_voucher_id' => $costAlloc->id,
        ]);
        $resCA->assertStatus(200);
        $this->assertEquals(45000000, $resCA->json('data.total_amount'));
        $this->assertEquals('155', $resCA->json('data.lines.0.debit_account'));
        $this->assertEquals('154', $resCA->json('data.lines.0.credit_account'));
    }
}
