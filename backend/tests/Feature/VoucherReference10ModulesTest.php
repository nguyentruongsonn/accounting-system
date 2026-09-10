<?php

namespace Tests\Feature;

use App\Enums\SystemVoucherType;
use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\BorrowingContract;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\CostAllocation;
use App\Models\Customer;
use App\Models\FixedAsset;
use App\Models\Item;
use App\Models\Payroll;
use App\Models\ProductionOrder;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Supplier;
use App\Models\ToolEquipment;
use App\Models\User;
use App\Services\SystemVoucherTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoucherReference10ModulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bankAccount;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Công ty Test 10 Phân Hệ MISA',
            'tax_code' => '0101999999',
            'address' => 'Hà Nội',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);
        Sanctum::actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH-M1-01',
            'name' => 'Công ty Mua Hàng M1',
            'address' => 'Hà Nội',
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC-M1-01',
            'name' => 'Công ty Bán Hàng M1',
            'address' => 'TP.HCM',
        ]);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '1122334455',
            'bank_name' => 'Vietcombank',
            'branch' => 'Sở Giao Dịch',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'SP-TP01',
            'name' => 'Thành phẩm sản xuất POS',
            'unit' => 'Chiếc',
            'cost_price' => 1000000,
            'selling_price' => 1500000,
        ]);
    }

    /**
     * 1. Test SystemVoucherType Enum and Registry Coverage
     */
    public function test_system_voucher_type_registry_and_enum(): void
    {
        $all = SystemVoucherTypeRegistry::all();
        $this->assertNotEmpty($all);
        $this->assertGreaterThanOrEqual(15, count($all));

        // Check modules presence
        $modules = collect($all)->pluck('module')->unique()->toArray();
        $this->assertContains('fixed_asset', $modules);
        $this->assertContains('tool', $modules);
        $this->assertContains('sales', $modules);
        $this->assertContains('purchase', $modules);
        $this->assertContains('cash', $modules);
        $this->assertContains('bank', $modules);
        $this->assertContains('inventory', $modules);
        $this->assertContains('payroll', $modules);
        $this->assertContains('gl', $modules);
        $this->assertContains('other', $modules);

        // Test TT200 / TT133 account mappings
        $salesType = SystemVoucherType::SALES_INVOICE;
        $this->assertEquals('131', $salesType->defaultDebitAccount('TT200'));
        $this->assertEquals('5111', $salesType->defaultCreditAccount('TT200'));

        $payrollType = SystemVoucherType::PAYROLL;
        $this->assertEquals('6421', $payrollType->defaultDebitAccount('TT200'));
        $this->assertEquals('6422', $payrollType->defaultDebitAccount('TT133'));
        $this->assertEquals('3341', $payrollType->defaultCreditAccount('TT200'));
    }

    /**
     * 2. Test ToolEquipment Model & HasVoucherReferences Trait
     */
    public function test_tool_equipment_model_and_references(): void
    {
        $tool = ToolEquipment::create([
            'company_id' => $this->company->id,
            'tool_code' => 'CCDC-TEST-01',
            'tool_name' => 'Máy khoan bê tông Bosch',
            'purchase_date' => '2026-08-01',
            'original_cost' => 6000000,
            'allocation_months' => 12,
            'monthly_allocation' => 500000,
            'accumulated_allocation' => 0,
            'remaining_value' => 6000000,
            'tool_account' => '242',
            'expense_account' => '6423',
            'is_active' => true,
        ]);

        $this->assertNotNull($tool->id);
        $this->assertEquals('CCDC-TEST-01', $tool->tool_code);
        $this->assertEquals(6000000, $tool->original_cost);

        // Sync references
        $tool->syncReferences([
            [
                'target_type' => PurchaseInvoice::class,
                'target_id' => 1,
                'voucher_type' => 'Hóa đơn mua hàng',
                'voucher_number' => 'HDMH-001',
                'voucher_date' => '2026-08-01',
                'total_amount' => 6000000,
                'description' => 'Mua CCDC theo hóa đơn HDMH-001',
            ],
        ]);

        $this->assertCount(1, $tool->references);
        $this->assertEquals('HDMH-001', $tool->references->first()->target_voucher_number);
    }

    /**
     * 3. Test Trait HasVoucherReferences on FixedAsset, Payroll, BorrowingContract, ProductionOrder, CostAllocation
     */
    public function test_has_voucher_references_on_all_new_models(): void
    {
        // 1. Fixed Asset
        $fa = FixedAsset::create([
            'company_id' => $this->company->id,
            'asset_code' => 'TSCD-REF-01',
            'asset_name' => 'Xe tải Isuzu 2.5T',
            'purchase_date' => '2026-08-01',
            'original_cost' => 450000000,
            'depreciable_cost' => 450000000,
            'useful_life_months' => 60,
            'is_active' => true,
        ]);
        $fa->syncReferences([
            [
                'target_type' => 'PurchaseInvoice',
                'target_id' => 10,
                'voucher_type' => 'Hóa đơn mua hàng',
                'voucher_number' => 'HDMH-ISUZU',
                'total_amount' => 450000000,
            ],
        ]);
        $this->assertCount(1, $fa->references);

        // 2. Payroll
        $payroll = Payroll::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'BL-08-2026',
            'voucher_date' => '2026-08-31',
            'month' => '2026-08',
            'description' => 'Bảng lương tháng 08/2026',
            'total_amount' => 85000000,
            'is_posted' => false,
        ]);
        $payroll->syncReferences([
            [
                'target_type' => 'JournalEntry',
                'target_id' => 20,
                'voucher_type' => 'Chứng từ nghiệp vụ khác',
                'voucher_number' => 'PKT-020',
                'total_amount' => 85000000,
            ],
        ]);
        $this->assertCount(1, $payroll->references);

        // 3. Borrowing Contract
        $borrowing = BorrowingContract::create([
            'company_id' => $this->company->id,
            'contract_number' => 'KUV-BIDV-01',
            'credit_contract' => 'HDTD-001',
            'lender_name' => 'BIDV Chi nhánh Hà Nội',
            'purpose' => 'Vay vốn lưu động',
            'amount' => 500000000,
            'disbursement_date' => '2026-08-15',
            'maturity_date' => '2027-08-15',
            'status' => 'active',
        ]);
        $borrowing->syncReferences([
            [
                'target_type' => 'BankReceipt',
                'target_id' => 30,
                'voucher_type' => 'Thu tiền gửi',
                'voucher_number' => 'BC-030',
                'total_amount' => 500000000,
            ],
        ]);
        $this->assertCount(1, $borrowing->references);

        // 4. Production Order
        $prodOrder = ProductionOrder::create([
            'company_id' => $this->company->id,
            'order_number' => 'LSX-2026-001',
            'start_date' => '2026-08-01',
            'item_id' => $this->item->id,
            'planned_quantity' => 100,
            'actual_quantity' => 100,
            'status' => 'completed',
        ]);
        $prodOrder->syncReferences([
            [
                'target_type' => 'SalesOrder',
                'target_id' => 40,
                'voucher_type' => 'Đơn đặt hàng',
                'voucher_number' => 'DDH-040',
                'total_amount' => 150000000,
            ],
        ]);
        $this->assertCount(1, $prodOrder->references);

        // 5. Cost Allocation
        $costAlloc = CostAllocation::create([
            'company_id' => $this->company->id,
            'production_order_id' => $prodOrder->id,
            'month' => '2026-08',
            'direct_material_cost' => 70000000,
            'direct_labor_cost' => 20000000,
            'manufacturing_overhead' => 10000000,
            'total_cost' => 100000000,
            'is_posted' => false,
        ]);
        $costAlloc->syncReferences([
            [
                'target_type' => ProductionOrder::class,
                'target_id' => $prodOrder->id,
                'voucher_type' => 'Lệnh sản xuất',
                'voucher_number' => $prodOrder->order_number,
                'total_amount' => 100000000,
            ],
        ]);
        $this->assertCount(1, $costAlloc->references);
    }

    /**
     * 4. Test VoucherReferenceController::search Returns All 10 Modules
     */
    public function test_voucher_reference_search_expanded_modules(): void
    {
        // 1. Create FixedAsset
        FixedAsset::create([
            'company_id' => $this->company->id,
            'asset_code' => 'TSCD-SRCH-99',
            'asset_name' => 'Máy chủ Dell PowerEdge',
            'purchase_date' => '2026-08-05',
            'original_cost' => 80000000,
            'depreciable_cost' => 80000000,
            'useful_life_months' => 36,
            'is_active' => true,
        ]);

        // 2. Create ToolEquipment
        ToolEquipment::create([
            'company_id' => $this->company->id,
            'tool_code' => 'CCDC-SRCH-99',
            'tool_name' => 'Bộ dụng cụ cơ khí chuyên dụng',
            'purchase_date' => '2026-08-06',
            'original_cost' => 15000000,
            'allocation_months' => 12,
            'is_active' => true,
        ]);

        // 3. Create Payroll
        Payroll::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'BL-SRCH-99',
            'voucher_date' => '2026-08-31',
            'month' => '2026-08',
            'description' => 'Bảng lương tháng 8 tìm kiếm',
            'total_amount' => 65000000,
        ]);

        // 4. Create Borrowing Contract
        BorrowingContract::create([
            'company_id' => $this->company->id,
            'contract_number' => 'KUV-SRCH-99',
            'credit_contract' => 'HDTD-99',
            'lender_name' => 'Ngân hàng VietinBank',
            'amount' => 300000000,
            'disbursement_date' => '2026-08-10',
            'maturity_date' => '2027-08-10',
            'status' => 'active',
        ]);

        // Search All
        $resAll = $this->getJson('/api/v1/voucher-references/search');
        $resAll->assertStatus(200);
        $dataAll = $resAll->json('data');

        $this->assertTrue(collect($dataAll)->contains('voucher_number', 'TSCD-SRCH-99'));
        $this->assertTrue(collect($dataAll)->contains('voucher_number', 'CCDC-SRCH-99'));
        $this->assertTrue(collect($dataAll)->contains('voucher_number', 'BL-SRCH-99'));
        $this->assertTrue(collect($dataAll)->contains('voucher_number', 'KUV-SRCH-99'));

        // Search by module_group = fixed_asset
        $resFA = $this->getJson('/api/v1/voucher-references/search?module_group=fixed_asset');
        $resFA->assertStatus(200);
        $dataFA = $resFA->json('data');
        $this->assertTrue(collect($dataFA)->contains('voucher_number', 'TSCD-SRCH-99'));
        $this->assertFalse(collect($dataFA)->contains('voucher_number', 'BL-SRCH-99'));

        // Search by search_by=voucher_type & search_value=Tiền lương
        $resPR = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_type&search_value=Tiền lương');
        $resPR->assertStatus(200);
        $dataPR = $resPR->json('data');
        $this->assertTrue(collect($dataPR)->contains('voucher_number', 'BL-SRCH-99'));
        $this->assertFalse(collect($dataPR)->contains('voucher_number', 'TSCD-SRCH-99'));
    }

    /**
     * 5. Test POST /api/v1/voucher-references/resolve-defaults Cross-Voucher Auto-Fill
     */
    public function test_resolve_defaults_cross_voucher_autofill(): void
    {
        // 1. SalesInvoice -> CashReceipt
        $salesInv = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'invoice_number' => 'HDBH-RESOLVE-01',
            'invoice_date' => '2026-08-20',
            'total_amount' => 12000000,
        ]);
        SalesInvoiceLine::create([
            'sales_invoice_id' => $salesInv->id,
            'debit_account' => '131',
            'credit_account' => '511',
            'amount' => 12000000,
        ]);

        $res1 = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'CashReceipt',
            'target_voucher_type' => 'SalesInvoice',
            'target_voucher_id' => $salesInv->id,
        ]);
        $res1->assertStatus(200);
        $res1Data = $res1->json('data');
        $this->assertEquals($this->customer->id, $res1Data['contact_id']);
        $this->assertEquals(12000000, $res1Data['total_amount']);
        $this->assertStringContainsString('HDBH-RESOLVE-01', $res1Data['description']);
        $this->assertEquals('1111', $res1Data['lines'][0]['debit_account']);
        $this->assertEquals('131', $res1Data['lines'][0]['credit_account']);
        $this->assertEquals($salesInv->id, $res1Data['lines'][0]['invoice_id']);

        // 2. PurchaseInvoice -> BankPayment
        $purchaseInv = PurchaseInvoice::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'HDMH-RESOLVE-01',
            'invoice_date' => '2026-08-20',
            'total_amount' => 25000000,
        ]);
        PurchaseInvoiceLine::create([
            'purchase_invoice_id' => $purchaseInv->id,
            'debit_account' => '1561',
            'credit_account' => '331',
            'amount' => 25000000,
        ]);

        $res2 = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'bank_payment',
            'target_voucher_type' => 'purchase_invoice',
            'target_voucher_id' => $purchaseInv->id,
        ]);
        $res2->assertStatus(200);
        $res2Data = $res2->json('data');
        $this->assertEquals($this->supplier->id, $res2Data['contact_id']);
        $this->assertEquals(25000000, $res2Data['total_amount']);
        $this->assertEquals('331', $res2Data['lines'][0]['debit_account']);
        $this->assertEquals('1121', $res2Data['lines'][0]['credit_account']);

        // 3. Payroll -> CashPayment
        $payroll = Payroll::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'BL-RESOLVE-08',
            'voucher_date' => '2026-08-31',
            'month' => '2026-08',
            'total_amount' => 90000000,
        ]);

        $res3 = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'CashPayment',
            'target_voucher_type' => 'Payroll',
            'target_voucher_id' => $payroll->id,
        ]);
        $res3->assertStatus(200);
        $res3Data = $res3->json('data');
        $this->assertEquals(90000000, $res3Data['total_amount']);
        $this->assertEquals('3341', $res3Data['lines'][0]['debit_account']);
        $this->assertEquals('1111', $res3Data['lines'][0]['credit_account']);

        // 4. BorrowingContract -> BankReceipt
        $borrow = BorrowingContract::create([
            'company_id' => $this->company->id,
            'contract_number' => 'KUV-RESOLVE-01',
            'lender_name' => 'Ngân hàng Sacombank',
            'amount' => 1000000000,
            'disbursement_date' => '2026-08-20',
            'maturity_date' => '2027-08-20',
        ]);

        $res4 = $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'BankReceipt',
            'target_voucher_type' => 'BorrowingContract',
            'target_voucher_id' => $borrow->id,
        ]);
        $res4->assertStatus(200);
        $res4Data = $res4->json('data');
        $this->assertEquals('Ngân hàng Sacombank', $res4Data['contact_name']);
        $this->assertEquals('1121', $res4Data['lines'][0]['debit_account']);
        $this->assertEquals('3411', $res4Data['lines'][0]['credit_account']);
    }
}
