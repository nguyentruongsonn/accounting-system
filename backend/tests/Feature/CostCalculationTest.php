<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CostCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Warehouse $warehouse;

    protected Warehouse $warehouse2;

    protected Supplier $supplier;

    protected Item $itemA;

    protected Item $itemB;

    protected Item $rawItem;

    protected function setUp(): void
    {
        parent::setUp();
        TwoRolePermissions::seed();
        config()->set('accounting.enforce_inventory_stock_availability', false);

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Công ty Cổ phần Tính Giá MISA',
                'tax_code' => '0105556667',
                'address' => 'Hà Nội, Việt Nam',
                'is_active' => true,
            ]
        );

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Kế toán giá thành MISA',
            'email' => 'costing_test_'.uniqid().'@example.com',
        ]);
        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);

        $this->fiscalYear = FiscalYear::firstOrCreate(
            ['id' => 1],
            [
                'company_id' => $this->company->id,
                'name' => 'Năm 2026',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
                'is_closed' => false,
            ]
        );

        // Chart of Accounts
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1331', 'name' => 'Thuế GTGT khấu trừ', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '152', 'name' => 'Nguyên vật liệu', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '155', 'name' => 'Thành phẩm', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1561', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '331', 'name' => 'Phải trả người bán', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '621', 'name' => 'Chi phí NVL trực tiếp', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '642', 'name' => 'Chi phí quản lý DN', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit'],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'code' => $acc['code'],
                ],
                array_merge($acc, [
                    'company_id' => $this->company->id,
                    'is_active' => true,
                    'is_parent' => false,
                    'level' => 1,
                ])
            );
        }

        $this->warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO_CHINH'],
            [
                'company_id' => $this->company->id,
                'name' => 'Kho Chính',
                'account_code' => '1561',
            ]
        );

        $this->warehouse2 = Warehouse::firstOrCreate(
            ['code' => 'KHO_PHU'],
            [
                'company_id' => $this->company->id,
                'name' => 'Kho Phụ',
                'account_code' => '1561',
            ]
        );

        $this->supplier = Supplier::firstOrCreate(
            ['code' => 'NCC_COST_01'],
            [
                'company_id' => $this->company->id,
                'name' => 'Nhà Cung Cấp Tổng Hợp',
                'tax_code' => '0108889991',
                'address' => 'Hải Phòng',
            ]
        );

        $this->itemA = Item::firstOrCreate(
            ['code' => 'ITEM_A_VAL'],
            [
                'company_id' => $this->company->id,
                'name' => 'Sản phẩm A Tính Giá',
                'unit' => 'Hộp',
                'purchase_price' => 50000,
                'selling_price' => 80000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'type' => 'Goods',
            ]
        );

        $this->itemB = Item::firstOrCreate(
            ['code' => 'ITEM_B_VAL'],
            [
                'company_id' => $this->company->id,
                'name' => 'Sản phẩm B Tính Giá',
                'unit' => 'Cái',
                'purchase_price' => 100000,
                'selling_price' => 150000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'type' => 'Goods',
            ]
        );

        $this->rawItem = Item::firstOrCreate(
            ['code' => 'RAW_MAT_VAL'],
            [
                'company_id' => $this->company->id,
                'name' => 'Nguyên liệu thô X',
                'unit' => 'Kg',
                'purchase_price' => 20000,
                'selling_price' => 25000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '152',
                'type' => 'RawMaterial',
            ]
        );
    }

    public function test_monthly_weighted_average_cost_calculation_basic(): void
    {
        // 1. Receipt 1: 100 units @ 10,000 = 1,000,000 on 2026-08-05
        $rcpt1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-WA-01',
            'voucher_date' => '2026-08-05',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 100,
                    'unit_price' => 10000,
                    'amount' => 1000000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $rcpt1Id = $rcpt1->json('data.id') ?? $rcpt1->json('id');
        $this->postJson("/api/v1/inventory/receipts/{$rcpt1Id}/post")->assertStatus(200);

        // 2. Receipt 2: 100 units @ 14,000 = 1,400,000 on 2026-08-15
        $rcpt2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-WA-02',
            'voucher_date' => '2026-08-15',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 100,
                    'unit_price' => 14000,
                    'amount' => 1400000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $rcpt2Id = $rcpt2->json('data.id') ?? $rcpt2->json('id');
        $this->postJson("/api/v1/inventory/receipts/{$rcpt2Id}/post")->assertStatus(200);

        // Total available = 200 units, Total cost = 2,400,000 -> Weighted Average unit cost = 12,000

        // 3. Issue 1: 40 units on 2026-08-10 (initial unit price 0)
        $issue1 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-WA-01',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 40,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issue1Id = $issue1->json('data.id') ?? $issue1->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issue1Id}/post")->assertStatus(200);

        // 4. Issue 2: 60 units on 2026-08-20 (initial unit price 0)
        $issue2 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-WA-02',
            'voucher_date' => '2026-08-20',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 60,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issue2Id = $issue2->json('data.id') ?? $issue2->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issue2Id}/post")->assertStatus(200);

        // 5. Run Monthly Weighted Average calculation
        $calcRes = $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ]);

        $calcRes->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify Issue 1: 40 * 12,000 = 480,000
        $updatedIssue1 = InventoryIssue::with(['lines', 'journalEntry.lines'])->find($issue1Id);
        $this->assertEquals(480000, $updatedIssue1->total_amount);
        $this->assertEquals(12000, $updatedIssue1->lines[0]->unit_price);
        $this->assertEquals(480000, $updatedIssue1->lines[0]->amount);

        // Verify Issue 1 Journal Entry lines
        $je1 = $updatedIssue1->journalEntry;
        $this->assertNotNull($je1);
        $this->assertEquals(480000, $je1->total_amount);
        $this->assertEquals(480000, $je1->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(480000, $je1->lines->firstWhere('account_code', '1561')->credit_amount);

        // Verify Issue 2: 60 * 12,000 = 720,000
        $updatedIssue2 = InventoryIssue::with(['lines', 'journalEntry.lines'])->find($issue2Id);
        $this->assertEquals(720000, $updatedIssue2->total_amount);
        $this->assertEquals(12000, $updatedIssue2->lines[0]->unit_price);
        $this->assertEquals(720000, $updatedIssue2->lines[0]->amount);

        // Verify Issue 2 Journal Entry lines
        $je2 = $updatedIssue2->journalEntry;
        $this->assertNotNull($je2);
        $this->assertEquals(720000, $je2->total_amount);
        $this->assertEquals(720000, $je2->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(720000, $je2->lines->firstWhere('account_code', '1561')->credit_amount);
    }

    public function test_monthly_weighted_average_with_opening_stock(): void
    {
        // 1. Inward in July 2026: 50 units @ 20,000 = 1,000,000 on 2026-07-15
        $julyRcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-JULY-01',
            'voucher_date' => '2026-07-15',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 50, 'unit_price' => 20000, 'amount' => 1000000],
            ],
        ]);
        $julyRcptId = $julyRcpt->json('data.id') ?? $julyRcpt->json('id');
        $this->postJson("/api/v1/inventory/receipts/{$julyRcptId}/post")->assertStatus(200);

        // 2. Outward in July 2026: 20 units @ 20,000 = 400,000 on 2026-07-25
        $julyIssue = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-JULY-01',
            'voucher_date' => '2026-07-25',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 20, 'unit_price' => 20000, 'amount' => 400000],
            ],
        ]);
        $julyIssueId = $julyIssue->json('data.id') ?? $julyIssue->json('id');
        $this->postJson("/api/v1/inventory/issues/{$julyIssueId}/post")->assertStatus(200);

        // Opening balance on 2026-08-01: Qty = 30 units, Amt = 600,000

        // 3. Receipt in August 2026: 70 units @ 30,000 = 2,100,000 on 2026-08-10
        $augRcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-AUG-01',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 70, 'unit_price' => 30000, 'amount' => 2100000],
            ],
        ]);
        $augRcptId = $augRcpt->json('data.id') ?? $augRcpt->json('id');
        $this->postJson("/api/v1/inventory/receipts/{$augRcptId}/post")->assertStatus(200);

        // Total available in August = 30 + 70 = 100 units. Total amount = 600,000 + 2,100,000 = 2,700,000 -> Unit cost = 27,000

        // 4. Issue in August 2026: 50 units on 2026-08-18
        $augIssue = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-AUG-01',
            'voucher_date' => '2026-08-18',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 50, 'unit_price' => 0, 'amount' => 0],
            ],
        ]);
        $augIssueId = $augIssue->json('data.id') ?? $augIssue->json('id');
        $this->postJson("/api/v1/inventory/issues/{$augIssueId}/post")->assertStatus(200);

        // 5. Run cost calculation for August
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ])->assertStatus(200);

        // Verify: 50 units * 27,000 = 1,350,000
        $updatedAugIssue = InventoryIssue::with('journalEntry.lines')->find($augIssueId);
        $this->assertEquals(1350000, $updatedAugIssue->total_amount);
        $this->assertEquals(27000, $updatedAugIssue->lines[0]->unit_price);
        $this->assertEquals(1350000, $updatedAugIssue->lines[0]->amount);
        $this->assertEquals(1350000, $updatedAugIssue->journalEntry->total_amount);
        $this->assertEquals(1350000, $updatedAugIssue->journalEntry->lines->firstWhere('account_code', '632')->debit_amount);
    }

    public function test_fifo_cost_calculation_single_and_split_batches(): void
    {
        // Batch 1 (2026-08-01): 10 units @ 100,000 = 1,000,000
        $rcpt1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-01',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 10, 'unit_price' => 100000, 'amount' => 1000000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt1->json('data.id') ?? $rcpt1->json('id')).'/post')->assertStatus(200);

        // Batch 2 (2026-08-10): 10 units @ 150,000 = 1,500,000
        $rcpt2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-02',
            'voucher_date' => '2026-08-10',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 10, 'unit_price' => 150000, 'amount' => 1500000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt2->json('data.id') ?? $rcpt2->json('id')).'/post')->assertStatus(200);

        // Batch 3 (2026-08-20): 10 units @ 200,000 = 2,000,000
        $rcpt3 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-03',
            'voucher_date' => '2026-08-20',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 10, 'unit_price' => 200000, 'amount' => 2000000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt3->json('data.id') ?? $rcpt3->json('id')).'/post')->assertStatus(200);

        // Issue 1 (2026-08-05): 6 units -> Takes 6 from Batch 1 @ 100,000 = 600,000
        $iss1 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-01',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 6, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss1Id = $iss1->json('data.id') ?? $iss1->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss1Id}/post")->assertStatus(200);

        // Issue 2 (2026-08-15): 8 units -> Takes 4 from Batch 1 @ 100k (= 400k) + 4 from Batch 2 @ 150k (= 600k) = 1,000,000 (unit_price = 125,000)
        $iss2 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-02',
            'voucher_date' => '2026-08-15',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 8, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss2Id = $iss2->json('data.id') ?? $iss2->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss2Id}/post")->assertStatus(200);

        // Issue 3 (2026-08-25): 10 units -> Takes 6 from Batch 2 @ 150k (= 900k) + 4 from Batch 3 @ 200k (= 800k) = 1,700,000 (unit_price = 170,000)
        $iss3 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-03',
            'voucher_date' => '2026-08-25',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 10, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss3Id = $iss3->json('data.id') ?? $iss3->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss3Id}/post")->assertStatus(200);

        // Run FIFO cost calculation
        $calcRes = $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
        ]);
        $calcRes->assertStatus(200);

        // Verify Issue 1
        $updated1 = InventoryIssue::with('journalEntry.lines')->find($iss1Id);
        $this->assertEquals(600000, $updated1->total_amount);
        $this->assertEquals(100000, $updated1->lines[0]->unit_price);
        $this->assertEquals(600000, $updated1->lines[0]->amount);
        $this->assertEquals(600000, $updated1->journalEntry->total_amount);

        // Verify Issue 2
        $updated2 = InventoryIssue::with('journalEntry.lines')->find($iss2Id);
        $this->assertEquals(1000000, $updated2->total_amount);
        $this->assertEquals(125000, $updated2->lines[0]->unit_price);
        $this->assertEquals(1000000, $updated2->lines[0]->amount);
        $this->assertEquals(1000000, $updated2->journalEntry->total_amount);

        // Verify Issue 3
        $updated3 = InventoryIssue::with('journalEntry.lines')->find($iss3Id);
        $this->assertEquals(1700000, $updated3->total_amount);
        $this->assertEquals(170000, $updated3->lines[0]->unit_price);
        $this->assertEquals(1700000, $updated3->lines[0]->amount);
        $this->assertEquals(1700000, $updated3->journalEntry->total_amount);
    }

    public function test_fifo_cost_calculation_with_prior_period_consumption(): void
    {
        // 1. July Receipt: 20 units @ 50,000 = 1,000,000 on 2026-07-15
        $julyRcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-PRIOR-01',
            'voucher_date' => '2026-07-15',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 20, 'unit_price' => 50000, 'amount' => 1000000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($julyRcpt->json('data.id') ?? $julyRcpt->json('id')).'/post')->assertStatus(200);

        // 2. July Issue: 15 units on 2026-07-20 (Consumes 15 from July batch, leaving 5 units @ 50k)
        $julyIss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-PRIOR-01',
            'voucher_date' => '2026-07-20',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 15, 'unit_price' => 50000, 'amount' => 750000]],
        ]);
        $this->postJson('/api/v1/inventory/issues/'.($julyIss->json('data.id') ?? $julyIss->json('id')).'/post')->assertStatus(200);

        // 3. August Receipt: 20 units @ 80,000 = 1,600,000 on 2026-08-05
        $augRcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-PRIOR-02',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 20, 'unit_price' => 80000, 'amount' => 1600000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($augRcpt->json('data.id') ?? $augRcpt->json('id')).'/post')->assertStatus(200);

        // 4. August Issue: 10 units on 2026-08-10 (Should take remaining 5 @ 50k [= 250k] + 5 @ 80k [= 400k] = 650,000 total)
        $augIss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-PRIOR-02',
            'voucher_date' => '2026-08-10',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 10, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $augIssId = $augIss->json('data.id') ?? $augIss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$augIssId}/post")->assertStatus(200);

        // Run FIFO for August
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
        ])->assertStatus(200);

        $updated = InventoryIssue::with('journalEntry.lines')->find($augIssId);
        $this->assertEquals(650000, $updated->total_amount);
        $this->assertEquals(65000, $updated->lines[0]->unit_price);
        $this->assertEquals(650000, $updated->lines[0]->amount);
        $this->assertEquals(650000, $updated->journalEntry->total_amount);
        $this->assertEquals(650000, $updated->journalEntry->lines->firstWhere('account_code', '632')->debit_amount);
    }

    public function test_cost_calculation_does_not_double_count_purchase_invoice_when_inventory_receipt_exists(): void
    {
        // 1. Purchase Invoice: 50 units @ 100,000 = 5,000,000 on 2026-08-02
        $pi = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-COST-PI-01',
            'invoice_date' => '2026-08-02',
            'due_date' => '2026-09-02',
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'quantity' => 50,
                    'unit_price' => 100000,
                    'amount' => 5000000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $piId = $pi->json('data.id') ?? $pi->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$piId}/post")->assertStatus(200);

        // 2. Inventory Receipt: 50 units @ 120,000 = 6,000,000 on 2026-08-12
        $rcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-COST-PI-01',
            'voucher_date' => '2026-08-12',
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 50, 'unit_price' => 120000, 'amount' => 6000000],
            ],
        ]);
        $rcptId = $rcpt->json('data.id') ?? $rcpt->json('id');
        $this->postJson("/api/v1/inventory/receipts/{$rcptId}/post")->assertStatus(200);

        // The purchase invoice is AP evidence only. The posted receipt is
        // the one stock movement: 50 units, 6,000,000 -> 120,000/unit.

        // 3. Issue: 40 units on 2026-08-20
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-COST-PI-01',
            'voucher_date' => '2026-08-20',
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 40, 'unit_price' => 0, 'amount' => 0],
            ],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        // Run Weighted Average calculation
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ])->assertStatus(200);

        // 40 * 120,000 = 4,800,000
        $updated = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(4800000, $updated->total_amount);
        $this->assertEquals(120000, $updated->lines[0]->unit_price);
        $this->assertEquals(4800000, $updated->lines[0]->amount);
        $this->assertEquals(4800000, $updated->journalEntry->total_amount);
    }

    public function test_cost_calculation_scoped_to_item_filter(): void
    {
        // Item A: 10 units @ 10,000
        $rcptA = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-SCOPE-A',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => 10, 'unit_price' => 10000, 'amount' => 100000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptA->json('data.id') ?? $rcptA->json('id')).'/post')->assertStatus(200);

        // Item B: 10 units @ 50,000
        $rcptB = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-SCOPE-B',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 10, 'unit_price' => 50000, 'amount' => 500000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptB->json('data.id') ?? $rcptB->json('id')).'/post')->assertStatus(200);

        // Issue for Item A: 2 units
        $issA = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-SCOPE-A',
            'voucher_date' => '2026-08-10',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => 2, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $issAId = $issA->json('data.id') ?? $issA->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issAId}/post")->assertStatus(200);

        // Issue for Item B: 2 units (initial amount 99999)
        $issB = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-SCOPE-B',
            'voucher_date' => '2026-08-10',
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => 2, 'unit_price' => 99999, 'amount' => 199998]],
        ]);
        $issBId = $issB->json('data.id') ?? $issB->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issBId}/post")->assertStatus(200);

        // Run calculation ONLY for Item A
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'item_id' => $this->itemA->id,
        ])->assertStatus(200);

        // Item A issue was recalculated (2 * 10,000 = 20,000)
        $updatedA = InventoryIssue::find($issAId);
        $this->assertEquals(20000, $updatedA->total_amount);

        // Item B issue remained untouched (199,998)
        $updatedB = InventoryIssue::find($issBId);
        $this->assertEquals(199998, $updatedB->total_amount);
    }

    public function test_cost_calculation_scoped_to_warehouse_filter(): void
    {
        // 1. Receipt in Warehouse 1: 10 units @ 10,000
        $rcptW1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-WH-01',
            'voucher_date' => '2026-08-05',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 10,
                    'unit_price' => 10000,
                    'amount' => 100000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptW1->json('data.id') ?? $rcptW1->json('id')).'/post')->assertStatus(200);

        // 2. Receipt in Warehouse 2: 10 units @ 30,000
        $rcptW2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-WH-02',
            'voucher_date' => '2026-08-05',
            'warehouse_id' => $this->warehouse2->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse2->id,
                    'quantity' => 10,
                    'unit_price' => 30000,
                    'amount' => 300000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptW2->json('data.id') ?? $rcptW2->json('id')).'/post')->assertStatus(200);

        // 3. Issue from Warehouse 1: 5 units
        $issW1 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-WH-01',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 5,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issW1Id = $issW1->json('data.id') ?? $issW1->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issW1Id}/post")->assertStatus(200);

        // 4. Issue from Warehouse 2: 5 units (initial unit price 30000, amount 150000)
        $issW2 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-WH-02',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouse2->id,
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouse2->id,
                    'quantity' => 5,
                    'unit_price' => 30000,
                    'amount' => 150000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issW2Id = $issW2->json('data.id') ?? $issW2->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issW2Id}/post")->assertStatus(200);

        // Run calculation ONLY for Warehouse 1
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'warehouse_id' => $this->warehouse->id,
        ])->assertStatus(200);

        // Warehouse 1 issue recalculated with Warehouse 1 unit cost (10,000) -> 5 * 10,000 = 50,000
        $updatedW1 = InventoryIssue::find($issW1Id);
        $this->assertEquals(50000, $updatedW1->total_amount);
        $this->assertEquals(10000, $updatedW1->lines[0]->unit_price);

        // Warehouse 2 issue remained untouched (150,000)
        $updatedW2 = InventoryIssue::find($issW2Id);
        $this->assertEquals(150000, $updatedW2->total_amount);
    }

    public function test_moving_average_realtime_cost_on_issue_creation(): void
    {
        // Receipt: 10 units @ 45,000 = 450,000
        $rcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MA-RT',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => 10, 'unit_price' => 45000, 'amount' => 450000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt->json('data.id') ?? $rcpt->json('id')).'/post')->assertStatus(200);

        // Create issue without specifying unit price (or unit_price = 0)
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-MA-RT',
            'voucher_date' => '2026-08-10',
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 4, 'unit_price' => 0, 'amount' => 0],
            ],
        ]);

        $iss->assertStatus(201);
        $id = $iss->json('data.id') ?? $iss->json('id');
        $createdIssue = InventoryIssue::with('lines')->find($id);

        // Auto-populated unit price = 45,000 and total amount = 180,000
        $this->assertEquals(45000, $createdIssue->lines[0]->unit_price);
        $this->assertEquals(180000, $createdIssue->total_amount);
    }

    public function test_cost_calculation_ignores_unposted_receipts(): void
    {
        // 1. Posted Receipt: 10 units @ 10,000
        $rcpt1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-POSTED-01',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => 10, 'unit_price' => 10000, 'amount' => 100000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt1->json('data.id') ?? $rcpt1->json('id')).'/post')->assertStatus(200);

        // 2. Draft/Unposted Receipt: 10 units @ 90,000 (NOT posted)
        $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-UNPOSTED-02',
            'voucher_date' => '2026-08-06',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => 10, 'unit_price' => 90000, 'amount' => 900000]],
        ])->assertStatus(201);

        // 3. Issue: 5 units
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-UNPOSTED-TEST',
            'voucher_date' => '2026-08-10',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => 5, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        // Run calculation
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ])->assertStatus(200);

        // Cost must be 10,000 (not 50,000)
        $updated = InventoryIssue::find($issId);
        $this->assertEquals(10000, $updated->lines[0]->unit_price);
        $this->assertEquals(50000, $updated->total_amount);
    }

    public function test_cost_calculation_recalculates_multi_reason_issue_gl_accounts(): void
    {
        // Stock receipt for Goods (ItemA): 10 units @ 10,000 = 100,000
        $rcptA = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MULTI-A',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => 10, 'unit_price' => 10000, 'amount' => 100000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptA->json('data.id') ?? $rcptA->json('id')).'/post')->assertStatus(200);

        // Stock receipt for Raw Materials: 100 kg @ 2,000 = 200,000
        $rcptRaw = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MULTI-RAW',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->rawItem->id, 'quantity' => 100, 'unit_price' => 2000, 'amount' => 200000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptRaw->json('data.id') ?? $rcptRaw->json('id')).'/post')->assertStatus(200);

        // Multi-line issue: Line 1 (Sales: 632 / 1561), Line 2 (Production: 621 / 152)
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-MULTI-RECALC',
            'voucher_date' => '2026-08-10',
            'lines' => [
                [
                    'item_id' => $this->itemA->id,
                    'quantity' => 5,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
                [
                    'item_id' => $this->rawItem->id,
                    'quantity' => 50,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '621',
                    'credit_account' => '152',
                ],
            ],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        // Run calculation
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ])->assertStatus(200);

        // Item A: 5 * 10,000 = 50,000 (632 / 1561)
        // Raw Item: 50 * 2,000 = 100,000 (621 / 152)
        // Total = 150,000
        $updated = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(150000, $updated->total_amount);

        $je = $updated->journalEntry;
        $this->assertEquals(150000, $je->total_amount);

        $this->assertEquals(50000, $je->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(50000, $je->lines->firstWhere('account_code', '1561')->credit_amount);
        $this->assertEquals(100000, $je->lines->firstWhere('account_code', '621')->debit_amount);
        $this->assertEquals(100000, $je->lines->firstWhere('account_code', '152')->credit_amount);
        $this->assertEquals($je->lines->sum('debit_amount'), $je->lines->sum('credit_amount'));
    }

    public function test_cost_calculation_fallback_to_purchase_price_when_zero_receipts(): void
    {
        // Item with purchase_price = 50,000, but no receipts in database
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FALLBACK-01',
            'voucher_date' => '2026-08-10',
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => 2, 'unit_price' => 0, 'amount' => 0],
            ],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        // Run calculation
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
        ])->assertStatus(200);

        // 2 units * purchase_price (50,000) = 100,000
        $updated = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(100000, $updated->total_amount);
        $this->assertEquals(50000, $updated->lines[0]->unit_price);
        $this->assertEquals(100000, $updated->journalEntry->total_amount);
    }
}
