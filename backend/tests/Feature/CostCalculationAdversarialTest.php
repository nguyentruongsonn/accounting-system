<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CostCalculationAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Supplier $supplier;

    protected Item $item1;

    protected Item $item2;

    protected Item $item3;

    protected Item $item4;

    protected Item $zeroCostItem;

    protected function setUp(): void
    {
        parent::setUp();
        TwoRolePermissions::seed();
        config()->set('accounting.enforce_inventory_stock_availability', false);

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Công ty Cổ phần Thử Thách Định Giá MISA',
                'tax_code' => '0109998881',
                'address' => 'Hà Nội, Việt Nam',
                'is_active' => true,
            ]
        );

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Kế toán trưởng Thử Thách',
            'email' => 'challenger_'.uniqid().'@example.com',
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

        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1331', 'name' => 'Thuế GTGT khấu trừ', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '153', 'name' => 'Công cụ, dụng cụ', 'type' => 'asset', 'nature' => 'debit'],
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

        $this->warehouseA = Warehouse::firstOrCreate(
            ['code' => 'KHO_ALPHA'],
            [
                'company_id' => $this->company->id,
                'name' => 'Kho Miền Bắc (Alpha)',
                'account_code' => '1561',
            ]
        );

        $this->warehouseB = Warehouse::firstOrCreate(
            ['code' => 'KHO_BETA'],
            [
                'company_id' => $this->company->id,
                'name' => 'Kho Miền Nam (Beta)',
                'account_code' => '1561',
            ]
        );

        $this->supplier = Supplier::firstOrCreate(
            ['code' => 'NCC_CHALLENGE'],
            [
                'company_id' => $this->company->id,
                'name' => 'Nhà Cung Cấp Thử Nghiệm',
                'tax_code' => '0107778889',
                'address' => 'Đà Nẵng',
            ]
        );

        $this->item1 = Item::firstOrCreate(
            ['code' => 'ITEM_ADV_1'],
            [
                'company_id' => $this->company->id,
                'name' => 'Hàng hóa 1 (Goods)',
                'unit' => 'Hộp',
                'purchase_price' => 45000,
                'selling_price' => 70000,
                'warehouse_id' => $this->warehouseA->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'type' => 'Goods',
            ]
        );

        $this->item2 = Item::firstOrCreate(
            ['code' => 'ITEM_ADV_2'],
            [
                'company_id' => $this->company->id,
                'name' => 'Nguyên vật liệu 2 (Raw Material)',
                'unit' => 'Kg',
                'purchase_price' => 15000,
                'selling_price' => 22000,
                'warehouse_id' => $this->warehouseA->id,
                'inventory_account' => '152',
                'cogs_account' => '621',
                'type' => 'RawMaterial',
            ]
        );

        $this->item3 = Item::firstOrCreate(
            ['code' => 'ITEM_ADV_3'],
            [
                'company_id' => $this->company->id,
                'name' => 'Công cụ dụng cụ 3 (Tool)',
                'unit' => 'Bộ',
                'purchase_price' => 120000,
                'selling_price' => 180000,
                'warehouse_id' => $this->warehouseA->id,
                'inventory_account' => '153',
                'cogs_account' => '642',
                'type' => 'Tool',
            ]
        );

        $this->item4 = Item::firstOrCreate(
            ['code' => 'ITEM_ADV_4'],
            [
                'company_id' => $this->company->id,
                'name' => 'Thành phẩm 4 (Finished Goods)',
                'unit' => 'Cái',
                'purchase_price' => 85000,
                'selling_price' => 130000,
                'warehouse_id' => $this->warehouseA->id,
                'inventory_account' => '155',
                'cogs_account' => '632',
                'type' => 'FinishedGoods',
            ]
        );

        $this->zeroCostItem = Item::firstOrCreate(
            ['code' => 'ITEM_ZERO_COST'],
            [
                'company_id' => $this->company->id,
                'name' => 'Hàng hóa Không giá (Zero Cost)',
                'unit' => 'Cái',
                'purchase_price' => 0,
                'cost_price' => 0,
                'selling_price' => 50000,
                'warehouse_id' => $this->warehouseA->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'type' => 'Goods',
            ]
        );
    }

    /**
     * 1. Monthly Weighted Average: Zero opening stock, zero inward stock -> Fallback to purchase_price
     */
    public function test_mwa_zero_opening_zero_inward_fallback_to_purchase_price(): void
    {
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-MWA-FALLBACK',
            'voucher_date' => '2026-08-10',
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'quantity' => 10,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        // The item has a positive resolved cost, so posting can derive a
        // positive GL line and remains a valid precondition for MWA.
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        // Run Monthly Weighted Average
        $calcRes = $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'item_id' => $this->item1->id,
        ]);
        $calcRes->assertStatus(200);

        $updatedIssue = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(45000, $updatedIssue->lines[0]->unit_price);
        $this->assertEquals(450000, $updatedIssue->lines[0]->amount);
        $this->assertEquals(450000, $updatedIssue->total_amount);

        // Verify Journal Entry Balance
        $je = $updatedIssue->journalEntry;
        $this->assertNotNull($je);
        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(450000, $sumDebit);
        $this->assertEquals(450000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);
    }

    /**
     * 2. Monthly Weighted Average: Zero opening stock, zero inward, zero item price -> Graceful zero, no crash
     */
    public function test_mwa_zero_opening_zero_inward_zero_item_price_handled_gracefully(): void
    {
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-ZERO-PRICE',
            'voucher_date' => '2026-08-10',
            'lines' => [
                [
                    'item_id' => $this->zeroCostItem->id,
                    'quantity' => 5,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        // Production posting is fail-closed when the issue has no positive GL amount.
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")
            ->assertStatus(422)
            ->assertJsonPath('errors.lines.0', 'Không thể ghi sổ phiếu xuất kho khi không có dòng hạch toán có số tiền dương.');

        $calcRes = $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'item_id' => $this->zeroCostItem->id,
        ]);
        $calcRes->assertStatus(200);

        $updatedIssue = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertFalse((bool) $updatedIssue->is_posted);
        $this->assertNull($updatedIssue->journal_entry_id);
        $this->assertEquals(0, $updatedIssue->lines[0]->unit_price);
        $this->assertEquals(0, $updatedIssue->lines[0]->amount);
        $this->assertEquals(0, $updatedIssue->total_amount);
    }

    /**
     * 3. Monthly Weighted Average: Multiple receipts at varying prices with fractional unit cost calculation
     */
    public function test_mwa_multiple_receipts_varying_prices_fractional_weighted_average(): void
    {
        // Receipt 1: 33 units @ 11,250 = 371,250 on 2026-08-02
        $rcpt1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MWA-V1',
            'voucher_date' => '2026-08-02',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 33, 'unit_price' => 11250, 'amount' => 371250]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt1->json('data.id') ?? $rcpt1->json('id')).'/post')->assertStatus(200);

        // Receipt 2: 47 units @ 19,850 = 932,950 on 2026-08-12
        $rcpt2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MWA-V2',
            'voucher_date' => '2026-08-12',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 47, 'unit_price' => 19850, 'amount' => 932950]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt2->json('data.id') ?? $rcpt2->json('id')).'/post')->assertStatus(200);

        // Receipt 3: 20 units @ 25,100 = 502,000 on 2026-08-22
        $rcpt3 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MWA-V3',
            'voucher_date' => '2026-08-22',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 20, 'unit_price' => 25100, 'amount' => 502000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt3->json('data.id') ?? $rcpt3->json('id')).'/post')->assertStatus(200);

        // Total Available: 100 units, Total Cost = 371,250 + 932,950 + 502,000 = 1,806,200 -> Average = 18,062.00

        // Issue 1: 25 units on 2026-08-15
        $iss1 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-MWA-V1',
            'voucher_date' => '2026-08-15',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 25, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss1Id = $iss1->json('data.id') ?? $iss1->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss1Id}/post")->assertStatus(200);

        // Issue 2: 75 units on 2026-08-25
        $iss2 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-MWA-V2',
            'voucher_date' => '2026-08-25',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 75, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss2Id = $iss2->json('data.id') ?? $iss2->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss2Id}/post")->assertStatus(200);

        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'item_id' => $this->item1->id,
        ])->assertStatus(200);

        $updated1 = InventoryIssue::with('journalEntry.lines')->find($iss1Id);
        $this->assertEquals(18062, $updated1->lines[0]->unit_price);
        $this->assertEquals(451550, $updated1->lines[0]->amount);
        $this->assertEquals(451550, $updated1->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(451550, $updated1->journalEntry->lines->sum('credit_amount'));

        $updated2 = InventoryIssue::with('journalEntry.lines')->find($iss2Id);
        $this->assertEquals(18062, $updated2->lines[0]->unit_price);
        $this->assertEquals(1354650, $updated2->lines[0]->amount);
        $this->assertEquals(1354650, $updated2->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(1354650, $updated2->journalEntry->lines->sum('credit_amount'));
    }

    /**
     * 4. FIFO: Zero opening stock, zero inward stock -> Fallback to item purchase_price
     */
    public function test_fifo_zero_opening_zero_inward_fallback_to_purchase_price(): void
    {
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-FALLBACK',
            'voucher_date' => '2026-08-10',
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'quantity' => 8,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
            'item_id' => $this->item1->id,
        ])->assertStatus(200);

        $updated = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(45000, $updated->lines[0]->unit_price);
        $this->assertEquals(360000, $updated->lines[0]->amount);
        $this->assertEquals(360000, $updated->total_amount);

        $sumDebit = $updated->journalEntry->lines->sum('debit_amount');
        $sumCredit = $updated->journalEntry->lines->sum('credit_amount');
        $this->assertEquals(360000, $sumDebit);
        $this->assertEquals(360000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);
    }

    /**
     * 5. FIFO: Multiple receipts varying prices and complex partial issue allocations
     */
    public function test_fifo_multiple_receipts_varying_prices_and_partial_issue_allocations(): void
    {
        // Batch 1 (2026-08-01): 15 units @ 10,000 = 150,000
        $rcpt1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-B1',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 15, 'unit_price' => 10000, 'amount' => 150000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt1->json('data.id') ?? $rcpt1->json('id')).'/post')->assertStatus(200);

        // Batch 2 (2026-08-05): 25 units @ 20,000 = 500,000
        $rcpt2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-B2',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 25, 'unit_price' => 20000, 'amount' => 500000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt2->json('data.id') ?? $rcpt2->json('id')).'/post')->assertStatus(200);

        // Batch 3 (2026-08-15): 30 units @ 30,000 = 900,000
        $rcpt3 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-B3',
            'voucher_date' => '2026-08-15',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 30, 'unit_price' => 30000, 'amount' => 900000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt3->json('data.id') ?? $rcpt3->json('id')).'/post')->assertStatus(200);

        // Issue 1 (2026-08-03): 10 units -> Takes 10 from Batch 1 @ 10k = 100k (5 remaining in B1)
        $iss1 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-I1',
            'voucher_date' => '2026-08-03',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 10, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss1Id = $iss1->json('data.id') ?? $iss1->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss1Id}/post")->assertStatus(200);

        // Issue 2 (2026-08-08): 15 units -> Takes 5 from B1 @ 10k (=50k) + 10 from B2 @ 20k (=200k) = 250,000 total.
        // The inventory issue schema stores calculated unit rates at four
        // places, so the trace rate is 16,666.6667 while the posted amount
        // remains the exact VND amount 250,000.00.
        $iss2 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-I2',
            'voucher_date' => '2026-08-08',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 15, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss2Id = $iss2->json('data.id') ?? $iss2->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss2Id}/post")->assertStatus(200);

        // Issue 3 (2026-08-20): 25 units -> Takes 15 from B2 @ 20k (=300k) + 10 from B3 @ 30k (=300k) = 600,000 total (unit_price = 24,000)
        $iss3 = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-I3',
            'voucher_date' => '2026-08-20',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 25, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $iss3Id = $iss3->json('data.id') ?? $iss3->json('id');
        $this->postJson("/api/v1/inventory/issues/{$iss3Id}/post")->assertStatus(200);

        // Run FIFO
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
            'item_id' => $this->item1->id,
        ])->assertStatus(200);

        // Verify Issue 1
        $u1 = InventoryIssue::with('journalEntry.lines')->find($iss1Id);
        $this->assertEquals(100000, $u1->total_amount);
        $this->assertEquals(10000, $u1->lines[0]->unit_price);
        $this->assertEquals(100000, $u1->lines[0]->amount);
        $this->assertEquals(100000, $u1->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(100000, $u1->journalEntry->lines->sum('credit_amount'));

        // Verify Issue 2
        $u2 = InventoryIssue::with('journalEntry.lines')->find($iss2Id);
        $this->assertEquals(250000, $u2->total_amount);
        $this->assertSame('16666.6667', $u2->lines[0]->unit_price);
        $this->assertEquals(250000, $u2->lines[0]->amount);
        $this->assertEquals(250000, $u2->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(250000, $u2->journalEntry->lines->sum('credit_amount'));

        // Verify Issue 3
        $u3 = InventoryIssue::with('journalEntry.lines')->find($iss3Id);
        $this->assertEquals(600000, $u3->total_amount);
        $this->assertEquals(24000, $u3->lines[0]->unit_price);
        $this->assertEquals(600000, $u3->lines[0]->amount);
        $this->assertEquals(600000, $u3->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(600000, $u3->journalEntry->lines->sum('credit_amount'));
    }

    /**
     * 6. FIFO: Issue quantity exceeds total available stock in inward batches -> Fallback price for excess
     */
    public function test_fifo_issue_exceeds_available_batches_fallback_behavior(): void
    {
        // Batch: 10 units @ 100,000 = 1,000,000
        $rcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-EXCESS-01',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 10, 'unit_price' => 100000, 'amount' => 1000000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt->json('data.id') ?? $rcpt->json('id')).'/post')->assertStatus(200);

        // Issue: 15 units (5 units over inward stock)
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-EXCESS-01',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 15, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
            'item_id' => $this->item1->id,
        ])->assertStatus(200);

        // Cost: 10 @ 100k + 5 @ 100k (last batch price fallback) = 1,500,000
        $updated = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(1500000, $updated->total_amount);
        $this->assertEquals(100000, $updated->lines[0]->unit_price);
        $this->assertEquals(1500000, $updated->lines[0]->amount);

        // Check strict GL balance
        $sumDebit = $updated->journalEntry->lines->sum('debit_amount');
        $sumCredit = $updated->journalEntry->lines->sum('credit_amount');
        $this->assertEquals(1500000, $sumDebit);
        $this->assertEquals(1500000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);
    }

    /**
     * 7. Multi-Warehouse Stock Isolation: Monthly Weighted Average
     */
    public function test_multi_warehouse_strict_cost_isolation_weighted_average(): void
    {
        // Warehouse A: 50 units @ 10,000 = 500,000
        $rcptA = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-ISO-WA-A',
            'voucher_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseA->id,
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouseA->id,
                    'quantity' => 50,
                    'unit_price' => 10000,
                    'amount' => 500000,
                ],
            ],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptA->json('data.id') ?? $rcptA->json('id')).'/post')->assertStatus(200);

        // Warehouse B: 50 units @ 40,000 = 2,000,000
        $rcptB = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-ISO-WA-B',
            'voucher_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseB->id,
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouseB->id,
                    'quantity' => 50,
                    'unit_price' => 40000,
                    'amount' => 2000000,
                ],
            ],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptB->json('data.id') ?? $rcptB->json('id')).'/post')->assertStatus(200);

        // Issue from Warehouse A: 20 units
        $issA = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-ISO-WA-A',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseA->id,
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouseA->id,
                    'quantity' => 20,
                    'unit_price' => 0,
                    'amount' => 0,
                ],
            ],
        ]);
        $issAId = $issA->json('data.id') ?? $issA->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issAId}/post")->assertStatus(200);

        // Issue from Warehouse B: 20 units
        $issB = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-ISO-WA-B',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseB->id,
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouseB->id,
                    'quantity' => 20,
                    'unit_price' => 0,
                    'amount' => 0,
                ],
            ],
        ]);
        $issBId = $issB->json('data.id') ?? $issB->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issBId}/post")->assertStatus(200);

        // 1. Run calculation scoped to Warehouse A
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'warehouse_id' => $this->warehouseA->id,
        ])->assertStatus(200);

        // Warehouse A issue recalculated @ 10,000 -> 200,000
        $updatedA = InventoryIssue::with('journalEntry.lines')->find($issAId);
        $this->assertEquals(200000, $updatedA->total_amount);
        $this->assertEquals(10000, $updatedA->lines[0]->unit_price);
        $this->assertEquals(200000, $updatedA->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(200000, $updatedA->journalEntry->lines->sum('credit_amount'));

        // Warehouse B issue was not updated by Warehouse A run (remains at its pre-existing amount 500,000)
        $unmodifiedB = InventoryIssue::find($issBId);
        $this->assertEquals(500000, $unmodifiedB->total_amount);

        // 2. Run calculation scoped to Warehouse B
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'warehouse_id' => $this->warehouseB->id,
        ])->assertStatus(200);

        // Warehouse B issue recalculated @ 40,000 -> 800,000
        $updatedB = InventoryIssue::with('journalEntry.lines')->find($issBId);
        $this->assertEquals(800000, $updatedB->total_amount);
        $this->assertEquals(40000, $updatedB->lines[0]->unit_price);
        $this->assertEquals(800000, $updatedB->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(800000, $updatedB->journalEntry->lines->sum('credit_amount'));
    }

    /**
     * 8. Multi-Warehouse Stock Isolation: FIFO
     */
    public function test_multi_warehouse_strict_cost_isolation_fifo(): void
    {
        // Warehouse A: Batch A1 (10 units @ 15,000), Batch A2 (10 units @ 25,000)
        $rcptA1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-ISO-A1',
            'voucher_date' => '2026-08-01',
            'warehouse_id' => $this->warehouseA->id,
            'lines' => [['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouseA->id, 'quantity' => 10, 'unit_price' => 15000, 'amount' => 150000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptA1->json('data.id') ?? $rcptA1->json('id')).'/post')->assertStatus(200);

        $rcptA2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-ISO-A2',
            'voucher_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseA->id,
            'lines' => [['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouseA->id, 'quantity' => 10, 'unit_price' => 25000, 'amount' => 250000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptA2->json('data.id') ?? $rcptA2->json('id')).'/post')->assertStatus(200);

        // Warehouse B: Batch B1 (10 units @ 50,000), Batch B2 (10 units @ 80,000)
        $rcptB1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-ISO-B1',
            'voucher_date' => '2026-08-01',
            'warehouse_id' => $this->warehouseB->id,
            'lines' => [['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouseB->id, 'quantity' => 10, 'unit_price' => 50000, 'amount' => 500000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptB1->json('data.id') ?? $rcptB1->json('id')).'/post')->assertStatus(200);

        $rcptB2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-ISO-B2',
            'voucher_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseB->id,
            'lines' => [['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouseB->id, 'quantity' => 10, 'unit_price' => 80000, 'amount' => 800000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcptB2->json('data.id') ?? $rcptB2->json('id')).'/post')->assertStatus(200);

        // Issue A (Warehouse A): 15 units -> 10 @ 15k (=150k) + 5 @ 25k (=125k) = 275,000
        $issA = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-ISO-A',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseA->id,
            'lines' => [['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouseA->id, 'quantity' => 15, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $issAId = $issA->json('data.id') ?? $issA->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issAId}/post")->assertStatus(200);

        // Issue B (Warehouse B): 15 units -> 10 @ 50k (=500k) + 5 @ 80k (=400k) = 900,000
        $issB = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FIFO-ISO-B',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseB->id,
            'lines' => [['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouseB->id, 'quantity' => 15, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $issBId = $issB->json('data.id') ?? $issB->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issBId}/post")->assertStatus(200);

        // Run FIFO for Warehouse A
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
            'warehouse_id' => $this->warehouseA->id,
        ])->assertStatus(200);

        $updatedA = InventoryIssue::with('journalEntry.lines')->find($issAId);
        $this->assertEquals(275000, $updatedA->total_amount);
        // Calculated inventory issue rates are DECIMAL(20,4); the line and
        // journal amount are still rounded once to the 2-place money scale.
        $this->assertSame('18333.3333', $updatedA->lines[0]->unit_price);
        $this->assertEquals(275000, $updatedA->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(275000, $updatedA->journalEntry->lines->sum('credit_amount'));

        // Run FIFO for Warehouse B
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
            'warehouse_id' => $this->warehouseB->id,
        ])->assertStatus(200);

        $updatedB = InventoryIssue::with('journalEntry.lines')->find($issBId);
        $this->assertEquals(900000, $updatedB->total_amount);
        $this->assertEquals(60000, $updatedB->lines[0]->unit_price);
        $this->assertEquals(900000, $updatedB->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(900000, $updatedB->journalEntry->lines->sum('credit_amount'));
    }

    /**
     * 9. Multi-line mixed accounts recalculation strictly balances GL
     */
    public function test_multi_line_mixed_accounts_recalculation_strictly_balances_gl(): void
    {
        // 1. Receipts for all 4 items
        // Item 1 (Goods): 100 @ 30,000 = 3,000,000
        $r1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MIX-01',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 100, 'unit_price' => 30000, 'amount' => 3000000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($r1->json('data.id') ?? $r1->json('id')).'/post')->assertStatus(200);

        // Item 2 (Raw Mat): 200 @ 12,000 = 2,400,000
        $r2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MIX-02',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item2->id, 'quantity' => 200, 'unit_price' => 12000, 'amount' => 2400000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($r2->json('data.id') ?? $r2->json('id')).'/post')->assertStatus(200);

        // Item 3 (Tool): 50 @ 110,000 = 5,500,000
        $r3 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MIX-03',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item3->id, 'quantity' => 50, 'unit_price' => 110000, 'amount' => 5500000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($r3->json('data.id') ?? $r3->json('id')).'/post')->assertStatus(200);

        // Item 4 (Finished Goods): 40 @ 75,000 = 3,000,000
        $r4 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MIX-04',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item4->id, 'quantity' => 40, 'unit_price' => 75000, 'amount' => 3000000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($r4->json('data.id') ?? $r4->json('id')).'/post')->assertStatus(200);

        // 2. Issue with 4 lines
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-MULTI-MIX-01',
            'voucher_date' => '2026-08-10',
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'quantity' => 10,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
                [
                    'item_id' => $this->item2->id,
                    'quantity' => 20,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '621',
                    'credit_account' => '152',
                ],
                [
                    'item_id' => $this->item3->id,
                    'quantity' => 5,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '642',
                    'credit_account' => '153',
                ],
                [
                    'item_id' => $this->item4->id,
                    'quantity' => 8,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '811',
                    'credit_account' => '155',
                ],
            ],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        // 3. Run cost calculation (Monthly Weighted Average)
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ])->assertStatus(200);

        // Expected costs:
        // Line 1: 10 * 30,000 = 300,000 (Debit 632 / Credit 1561)
        // Line 2: 20 * 12,000 = 240,000 (Debit 621 / Credit 152)
        // Line 3: 5 * 110,000 = 550,000 (Debit 642 / Credit 153)
        // Line 4: 8 * 75,000 = 600,000 (Debit 811 / Credit 155)
        // Total = 1,690,000

        $updated = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(1690000, $updated->total_amount);

        $je = $updated->journalEntry;
        $this->assertNotNull($je);
        $this->assertEquals(1690000, $je->total_amount);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(1690000, $sumDebit);
        $this->assertEquals(1690000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'GL Total Debit must strictly equal Total Credit');

        // Check account breakdown
        $this->assertEquals(300000, $je->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(300000, $je->lines->firstWhere('account_code', '1561')->credit_amount);

        $this->assertEquals(240000, $je->lines->firstWhere('account_code', '621')->debit_amount);
        $this->assertEquals(240000, $je->lines->firstWhere('account_code', '152')->credit_amount);

        $this->assertEquals(550000, $je->lines->firstWhere('account_code', '642')->debit_amount);
        $this->assertEquals(550000, $je->lines->firstWhere('account_code', '153')->credit_amount);

        $this->assertEquals(600000, $je->lines->firstWhere('account_code', '811')->debit_amount);
        $this->assertEquals(600000, $je->lines->firstWhere('account_code', '155')->credit_amount);
    }

    /**
     * 10. Lifecycle: unpost, update quantity, repost, and recalculate cost
     */
    public function test_unpost_modify_repost_and_cost_recalculation_lifecycle(): void
    {
        // 1. Receipt: 50 units @ 20,000 = 1,000,000
        $rcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-LIFECYCLE',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 50, 'unit_price' => 20000, 'amount' => 1000000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt->json('data.id') ?? $rcpt->json('id')).'/post')->assertStatus(200);

        // 2. Issue initial 5 units
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-LIFECYCLE',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 5, 'unit_price' => 20000, 'amount' => 100000]],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        $issueVoucher = InventoryIssue::find($issId);
        $this->assertTrue($issueVoucher->is_posted);
        $oldJeId = $issueVoucher->journal_entry_id;

        // 3. Unpost
        $this->postJson("/api/v1/inventory/issues/{$issId}/unpost")->assertStatus(200);
        $issueVoucher->refresh();
        $this->assertFalse($issueVoucher->is_posted);
        $this->assertEquals('voided', JournalEntry::find($oldJeId)->status);

        // 4. Update quantity to 12
        $this->putJson("/api/v1/inventory/issues/{$issId}", [
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 12, 'unit_price' => 20000, 'amount' => 240000]],
        ])->assertStatus(200);

        // 5. Repost
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);
        $issueVoucher->refresh();
        $this->assertTrue($issueVoucher->is_posted);

        // 6. Recalculate cost
        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ])->assertStatus(200);

        $finalIssue = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(240000, $finalIssue->total_amount);
        $this->assertEquals(20000, $finalIssue->lines[0]->unit_price);
        $this->assertEquals(240000, $finalIssue->lines[0]->amount);

        $finalJe = $finalIssue->journalEntry;
        $this->assertEquals(240000, $finalJe->total_amount);
        $this->assertEquals(240000, $finalJe->lines->sum('debit_amount'));
        $this->assertEquals(240000, $finalJe->lines->sum('credit_amount'));
        $this->assertEquals($finalJe->lines->sum('debit_amount'), $finalJe->lines->sum('credit_amount'));
    }

    /**
     * 11. Fractional quantities (2-decimal precision) and rounding
     */
    public function test_fractional_quantities_and_decimal_rounding_precision(): void
    {
        // Receipt: 10 units @ 10,000 = 100,000
        $rcpt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FRAC',
            'voucher_date' => '2026-08-01',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 10, 'unit_price' => 10000, 'amount' => 100000]],
        ]);
        $this->postJson('/api/v1/inventory/receipts/'.($rcpt->json('data.id') ?? $rcpt->json('id')).'/post')->assertStatus(200);

        // Issue: 0.35 units
        $iss = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FRAC',
            'voucher_date' => '2026-08-05',
            'lines' => [['item_id' => $this->item1->id, 'quantity' => 0.35, 'unit_price' => 0, 'amount' => 0]],
        ]);
        $issId = $iss->json('data.id') ?? $iss->json('id');
        $this->postJson("/api/v1/inventory/issues/{$issId}/post")->assertStatus(200);

        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ])->assertStatus(200);

        // 0.35 * 10,000 = 3,500.00
        $updated = InventoryIssue::with('journalEntry.lines')->find($issId);
        $this->assertEquals(3500, $updated->total_amount);
        $this->assertEquals(10000, $updated->lines[0]->unit_price);
        $this->assertEquals(3500, $updated->lines[0]->amount);

        $sumDebit = $updated->journalEntry->lines->sum('debit_amount');
        $sumCredit = $updated->journalEntry->lines->sum('credit_amount');
        $this->assertEquals(3500, $sumDebit);
        $this->assertEquals(3500, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);
    }
}
