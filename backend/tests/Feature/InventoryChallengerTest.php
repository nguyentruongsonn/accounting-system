<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse1;

    protected Warehouse $warehouse2;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Employee $employee;

    protected Item $item1;

    protected Item $item2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Challenger Test Corp',
                'tax_code' => '0109998887',
                'address' => 'Hanoi, Vietnam',
                'is_active' => true,
            ]
        );

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Adversarial Tester',
            'email' => 'adversarial_'.uniqid().'@example.com',
        ]);
        // Posting now requires one of the two canonical roles.  Keep this
        // adversarial fixture representative of a real accountant instead
        // of bypassing the production authorizer.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        FiscalYear::firstOrCreate(
            ['id' => 1],
            [
                'company_id' => $this->company->id,
                'name' => 'FY 2026',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
                'is_closed' => false,
            ]
        );

        // Required COA
        $coas = [
            '1111' => 'Tiền mặt',
            '1121' => 'Tiền gửi',
            '131' => 'Phải thu KH',
            '152' => 'Nguyên vật liệu',
            '153' => 'Công cụ dụng cụ',
            '154' => 'SXKD dở dang',
            '155' => 'Thành phẩm',
            '1561' => 'Hàng hóa',
            '331' => 'Phải trả người bán',
            '621' => 'CP NVL trực tiếp',
            '632' => 'Giá vốn hàng bán',
            '642' => 'CPQLDN',
            '711' => 'Thu nhập khác',
            '811' => 'Chi phí khác',
        ];

        foreach ($coas as $code => $name) {
            ChartOfAccount::firstOrCreate(
                ['company_id' => $this->company->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => 'asset',
                    'nature' => 'debit',
                    'level' => 1,
                    'is_parent' => false,
                    'is_active' => true,
                ]
            );
        }

        $this->warehouse1 = Warehouse::firstOrCreate(
            ['code' => 'WH_MAIN'],
            ['company_id' => $this->company->id, 'name' => 'Main Warehouse', 'account_code' => '1561']
        );

        $this->warehouse2 = Warehouse::firstOrCreate(
            ['code' => 'WH_SUB'],
            ['company_id' => $this->company->id, 'name' => 'Sub Warehouse', 'account_code' => '1561']
        );

        $this->customer = Customer::firstOrCreate(
            ['code' => 'CUST_01'],
            ['company_id' => $this->company->id, 'name' => 'Customer 1']
        );

        $this->supplier = Supplier::firstOrCreate(
            ['code' => 'SUPP_01'],
            ['company_id' => $this->company->id, 'name' => 'Supplier 1']
        );

        $this->employee = Employee::firstOrCreate(
            ['code' => 'EMP_01'],
            ['company_id' => $this->company->id, 'name' => 'Warehouse Manager']
        );

        $this->item1 = Item::firstOrCreate(
            ['code' => 'ITM_A'],
            [
                'company_id' => $this->company->id,
                'name' => 'Item A',
                'unit' => 'Pcs',
                'purchase_price' => 50000,
                'selling_price' => 75000,
                'warehouse_id' => $this->warehouse1->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'type' => 'Goods',
            ]
        );

        $this->item2 = Item::firstOrCreate(
            ['code' => 'ITM_B'],
            [
                'company_id' => $this->company->id,
                'name' => 'Item B',
                'unit' => 'Box',
                'purchase_price' => 120000,
                'selling_price' => 180000,
                'warehouse_id' => $this->warehouse2->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'type' => 'Goods',
            ]
        );

        // The production posting guard correctly rejects an issue whose
        // item/warehouse has no available stock.  Seed opening receipts via
        // the same API lifecycle used by operators so every challenge starts
        // from a valid, auditable inventory state without weakening the guard.
        foreach ([
            [$this->item1, $this->warehouse1, 'ITM_A'],
            [$this->item2, $this->warehouse2, 'ITM_B'],
        ] as [$item, $warehouse, $suffix]) {
            $receipt = $this->postJson('/api/v1/inventory/receipts', [
                'company_id' => $this->company->id,
                'voucher_number' => 'PNK-OPENING-'.$suffix,
                'voucher_date' => '2026-01-01',
                'posting_date' => '2026-01-01',
                'warehouse_id' => $warehouse->id,
                'description' => 'Opening stock fixture for inventory challenge',
                'lines' => [[
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 100,
                    'unit_price' => $item->purchase_price,
                    'amount' => $item->purchase_price * 100,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ]],
            ]);
            $receipt->assertCreated();
            $openingReceiptId = $receipt->json('data.id');
            $this->postJson("/api/v1/inventory/receipts/{$openingReceiptId}/post")
                ->assertOk();
        }
    }

    /**
     * Challenge 1: Stress test duplicate voucher generation and sequence collisions
     */
    public function test_adversarial_duplicate_generation_and_multiple_clones(): void
    {
        // Create initial issue
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-2026-0001',
            'voucher_date' => '2026-08-01',
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouse1->id,
                    'quantity' => 10,
                    'unit_price' => 50000,
                    'amount' => 500000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
            'referenced_vouchers' => [
                [
                    'voucher_type' => 'Đơn đặt hàng',
                    'voucher_number' => 'SO-001',
                    'voucher_date' => '2026-07-30',
                    'total_amount' => 500000,
                ],
            ],
        ]);
        $createRes->assertStatus(201);
        $origId = $createRes->json('data.id');

        // Post original
        $this->postJson("/api/v1/inventory/issues/{$origId}/post")->assertStatus(200);

        // Duplicate multiple times in sequence
        $dupIds = [];
        $dupNumbers = [];
        for ($i = 0; $i < 5; $i++) {
            $dupRes = $this->postJson("/api/v1/inventory/issues/{$origId}/duplicate");
            $dupRes->assertStatus(201);
            $dupId = $dupRes->json('data.id') ?? $dupRes->json('id');
            $dupNum = $dupRes->json('data.voucher_number') ?? $dupRes->json('voucher_number');

            $this->assertNotContains($dupId, $dupIds, 'Duplicate ID must be unique');
            $this->assertNotContains($dupNum, $dupNumbers, "Duplicate voucher number must be unique: {$dupNum}");
            $this->assertNotEquals('PXK-2026-0001', $dupNum);

            $dupIssue = InventoryIssue::with(['lines', 'references'])->find($dupId);
            $this->assertFalse($dupIssue->is_posted, 'Duplicate must be unposted');
            $this->assertEquals('draft', $dupIssue->status, 'Duplicate status must be draft');
            $this->assertNull($dupIssue->journal_entry_id, 'Duplicate must not link to original journal entry');
            $this->assertCount(1, $dupIssue->lines);
            $this->assertEquals(500000, $dupIssue->total_amount);
            $this->assertCount(1, $dupIssue->references, 'Voucher references should be replicated');

            $dupIds[] = $dupId;
            $dupNumbers[] = $dupNum;
        }

        // Check duplicate on InventoryReceipt
        $receiptCreate = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-2026-0001',
            'lines' => [
                [
                    'item_id' => $this->item2->id,
                    'quantity' => 5,
                    'unit_price' => 120000,
                    'amount' => 600000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $receiptId = $receiptCreate->json('data.id');
        $this->postJson("/api/v1/inventory/receipts/{$receiptId}/post")->assertStatus(200);

        $dupReceiptRes = $this->postJson("/api/v1/inventory/receipts/{$receiptId}/duplicate");
        $dupReceiptRes->assertStatus(201);
        $dupReceiptId = $dupReceiptRes->json('data.id');
        $dupReceipt = InventoryReceipt::find($dupReceiptId);
        $this->assertFalse($dupReceipt->is_posted);
        $this->assertNull($dupReceipt->journal_entry_id);
        $this->assertEquals('draft', $dupReceipt->status);
    }

    /**
     * Challenge 2: Stress test unpost / void / repost lifecycle and GL balance integrity
     */
    public function test_adversarial_unpost_void_repost_lifecycle(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-LIFECYCLE-01',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'description' => 'Lifecycle testing issue',
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouse1->id,
                    'quantity' => 4,
                    'unit_price' => 50000,
                    'amount' => 200000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
                [
                    'item_id' => $this->item2->id,
                    'warehouse_id' => $this->warehouse2->id,
                    'quantity' => 2,
                    'unit_price' => 120000,
                    'amount' => 240000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issueId = $createRes->json('data.id');

        // Post 1
        $postRes1 = $this->postJson("/api/v1/inventory/issues/{$issueId}/post");
        $postRes1->assertStatus(200);
        $issue1 = InventoryIssue::find($issueId);
        $jeId1 = $issue1->journal_entry_id;
        $this->assertNotNull($jeId1);
        $je1 = JournalEntry::with('lines')->find($jeId1);
        $this->assertEquals('posted', $je1->status);
        $this->assertEquals(440000, $je1->lines->sum('debit_amount'));
        $this->assertEquals(440000, $je1->lines->sum('credit_amount'));

        // Unpost 1
        $unpostRes1 = $this->postJson("/api/v1/inventory/issues/{$issueId}/unpost");
        $unpostRes1->assertStatus(200);
        $issueAfterUnpost1 = InventoryIssue::find($issueId);
        $this->assertFalse($issueAfterUnpost1->is_posted);
        $jeAfterUnpost1 = JournalEntry::find($jeId1);
        $this->assertEquals('voided', $jeAfterUnpost1->status);

        // Post 2 (Repost)
        $postRes2 = $this->postJson("/api/v1/inventory/issues/{$issueId}/post");
        $postRes2->assertStatus(200);
        $issue2 = InventoryIssue::find($issueId);
        $this->assertTrue($issue2->is_posted);
        $jeId2 = $issue2->journal_entry_id;
        $je2 = JournalEntry::with('lines')->find($jeId2);
        $this->assertEquals('posted', $je2->status);
        $this->assertEquals(440000, $je2->lines->sum('debit_amount'));
        $this->assertEquals(440000, $je2->lines->sum('credit_amount'));

        // Void (should behave same as unpost)
        $voidRes = $this->postJson("/api/v1/inventory/issues/{$issueId}/void");
        $voidRes->assertStatus(200);
        $issueAfterVoid = InventoryIssue::find($issueId);
        $this->assertFalse($issueAfterVoid->is_posted);
        $jeAfterVoid = JournalEntry::find($jeId2);
        $this->assertEquals('voided', $jeAfterVoid->status);

        // Repeating void on already unposted must fail
        $voidFailRes = $this->postJson("/api/v1/inventory/issues/{$issueId}/void");
        $voidFailRes->assertStatus(400);

        // Repeating unpost on already unposted must fail
        $unpostFailRes = $this->postJson("/api/v1/inventory/issues/{$issueId}/unpost");
        $unpostFailRes->assertStatus(400);
    }

    /**
     * Challenge 3: Stress test cascade deletion of posted vs unposted vouchers
     */
    public function test_adversarial_cascade_deletion_posted_and_unposted(): void
    {
        // 3A: Unposted issue deletion
        $unpostedRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-DEL-UNPOSTED',
            'lines' => [
                ['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouse1->id, 'quantity' => 1, 'unit_price' => 50000, 'amount' => 50000],
            ],
            'referenced_vouchers' => [
                ['voucher_type' => 'PO', 'voucher_number' => 'PO-001', 'voucher_date' => '2026-08-01', 'total_amount' => 50000],
            ],
        ]);
        $unpostedId = $unpostedRes->json('data.id');

        $this->deleteJson("/api/v1/inventory/issues/{$unpostedId}")->assertStatus(200);
        $this->assertDatabaseMissing('inventory_issues', ['id' => $unpostedId]);
        $this->assertDatabaseMissing('inventory_issue_lines', ['inventory_issue_id' => $unpostedId]);

        // 3B: Posted issue deletion
        $postedRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-DEL-POSTED',
            'lines' => [
                ['item_id' => $this->item2->id, 'warehouse_id' => $this->warehouse2->id, 'quantity' => 2, 'unit_price' => 120000, 'amount' => 240000],
            ],
            'referenced_vouchers' => [
                ['voucher_type' => 'PO', 'voucher_number' => 'PO-002', 'voucher_date' => '2026-08-01', 'total_amount' => 240000],
            ],
        ]);
        $postedId = $postedRes->json('data.id');
        $this->postJson("/api/v1/inventory/issues/{$postedId}/post")->assertStatus(200);
        $postedIssue = InventoryIssue::find($postedId);
        $jeId = $postedIssue->journal_entry_id;

        $this->deleteJson("/api/v1/inventory/issues/{$postedId}")->assertStatus(409);
        $this->assertDatabaseHas('inventory_issues', ['id' => $postedId, 'is_posted' => true]);
        $this->assertDatabaseHas('inventory_issue_lines', ['inventory_issue_id' => $postedId]);
        $this->assertDatabaseHas('journal_entries', ['id' => $jeId, 'status' => 'posted']);

        // 3C: Posted receipt deletion
        $receiptRes = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-DEL-POSTED',
            'lines' => [
                ['item_id' => $this->item1->id, 'warehouse_id' => $this->warehouse1->id, 'quantity' => 10, 'unit_price' => 50000, 'amount' => 500000, 'credit_account' => '331'],
            ],
        ]);
        $receiptId = $receiptRes->json('data.id');
        $this->postJson("/api/v1/inventory/receipts/{$receiptId}/post")->assertStatus(200);
        $receipt = InventoryReceipt::find($receiptId);
        $recJeId = $receipt->journal_entry_id;

        $this->deleteJson("/api/v1/inventory/receipts/{$receiptId}")->assertStatus(409);
        $this->assertDatabaseHas('inventory_receipts', ['id' => $receiptId, 'is_posted' => true]);
        $this->assertDatabaseHas('inventory_receipt_lines', ['inventory_receipt_id' => $receiptId]);
        $this->assertDatabaseHas('journal_entries', ['id' => $recJeId, 'status' => 'posted']);
    }

    /**
     * Challenge 4: Stress test resource payload completeness for all relations and line details
     */
    public function test_adversarial_resource_serialization_payload_completeness(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Xuất kho bán hàng',
            'contact_type' => 'customer',
            'contact_id' => $this->customer->id,
            'contact_name' => $this->customer->name,
            'receiver_name' => 'Nguyễn Văn Nhận',
            'receiver_address' => 'Số 10 Cầu Giấy, Hà Nội',
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->name,
            'warehouse_id' => $this->warehouse1->id,
            'voucher_number' => 'PXK-RESOURCE-01',
            'voucher_date' => '2026-08-12',
            'posting_date' => '2026-08-12',
            'description' => 'Xuất kho kiểm thử Resource Serialization',
            'attached_docs' => '3 tờ Hóa đơn GTGT',
            'currency' => 'VND',
            'exchange_rate' => 1,
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouse1->id,
                    'description' => 'Dòng hàng 1: Item A tại kho Main',
                    'quantity' => 3,
                    'unit_price' => 50000,
                    'amount' => 150000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
                [
                    'item_id' => $this->item2->id,
                    'warehouse_id' => $this->warehouse2->id,
                    'description' => 'Dòng hàng 2: Item B tại kho Sub',
                    'quantity' => 2,
                    'unit_price' => 120000,
                    'amount' => 240000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
            'referenced_vouchers' => [
                [
                    'voucher_type' => 'Đơn bán hàng',
                    'voucher_number' => 'BH-001',
                    'voucher_date' => '2026-08-01',
                    'total_amount' => 390000,
                ],
            ],
        ];

        // 4A: Test store response serialization
        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $createRes->assertStatus(201);
        $issueId = $createRes->json('data.id');

        // Check header serialization
        $createRes->assertJsonPath('data.voucher_type', '1. Xuất kho bán hàng')
            ->assertJsonPath('data.contact_type', 'customer')
            ->assertJsonPath('data.contact_id', $this->customer->id)
            ->assertJsonPath('data.contact_name', $this->customer->name)
            ->assertJsonPath('data.receiver_name', 'Nguyễn Văn Nhận')
            ->assertJsonPath('data.receiver_address', 'Số 10 Cầu Giấy, Hà Nội')
            ->assertJsonPath('data.employee_id', $this->employee->id)
            ->assertJsonPath('data.employee_name', $this->employee->name)
            ->assertJsonPath('data.warehouse_id', $this->warehouse1->id)
            ->assertJsonPath('data.description', 'Xuất kho kiểm thử Resource Serialization')
            ->assertJsonPath('data.attached_docs', '3 tờ Hóa đơn GTGT')
            ->assertJsonPath('data.total_amount', 390000)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_posted', false);

        // Check line items serialization
        $createRes->assertJsonPath('data.lines.0.item_id', $this->item1->id)
            ->assertJsonPath('data.lines.0.item_code', 'ITM_A')
            ->assertJsonPath('data.lines.0.item_name', 'Item A')
            ->assertJsonPath('data.lines.0.unit', 'Pcs')
            ->assertJsonPath('data.lines.0.warehouse_id', $this->warehouse1->id)
            ->assertJsonPath('data.lines.0.warehouse_code', 'WH_MAIN')
            ->assertJsonPath('data.lines.0.warehouse_name', 'Main Warehouse')
            ->assertJsonPath('data.lines.0.debit_account', '632')
            ->assertJsonPath('data.lines.0.credit_account', '1561')
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.lines.0.unit_price', 50000)
            ->assertJsonPath('data.lines.0.amount', 150000);

        $createRes->assertJsonPath('data.lines.1.item_id', $this->item2->id)
            ->assertJsonPath('data.lines.1.item_code', 'ITM_B')
            ->assertJsonPath('data.lines.1.item_name', 'Item B')
            ->assertJsonPath('data.lines.1.unit', 'Box')
            ->assertJsonPath('data.lines.1.warehouse_id', $this->warehouse2->id)
            ->assertJsonPath('data.lines.1.warehouse_code', 'WH_SUB')
            ->assertJsonPath('data.lines.1.warehouse_name', 'Sub Warehouse');

        // 4B: Test show response with journal entry after posting
        $this->postJson("/api/v1/inventory/issues/{$issueId}/post")->assertStatus(200);

        $showRes = $this->getJson("/api/v1/inventory/issues/{$issueId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.is_posted', true)
            ->assertJsonPath('data.status', 'posted');

        $this->assertNotNull($showRes->json('data.journal_entry_id'));
        $this->assertNotNull($showRes->json('data.journal_entry'));
        $this->assertNotEmpty($showRes->json('data.journal_entry.lines'));

        // 4C: Test Index listing serialization
        $indexRes = $this->getJson('/api/v1/inventory/issues?search=PXK-RESOURCE-01');
        $indexRes->assertStatus(200);
        $matched = collect($indexRes->json('data'))->firstWhere('voucher_number', 'PXK-RESOURCE-01');
        $this->assertNotNull($matched);
        $this->assertCount(2, $matched['lines']);
        $this->assertEquals('WH_MAIN', $matched['lines'][0]['warehouse_code']);
        $this->assertEquals('ITM_A', $matched['lines'][0]['item_code']);
    }

    /**
     * Challenge 5: Edge cases - floating point arithmetic and large quantities
     */
    public function test_adversarial_floating_point_precision_and_amounts(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FLOAT-01',
            'voucher_date' => '2026-08-15',
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'warehouse_id' => $this->warehouse1->id,
                    'quantity' => 3.333,
                    'unit_price' => 33333.33,
                    'amount' => 111099.99,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
                [
                    'item_id' => $this->item2->id,
                    'warehouse_id' => $this->warehouse2->id,
                    'quantity' => 7.777,
                    'unit_price' => 77777.77,
                    'amount' => 604877.72,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id');

        $postRes = $this->postJson("/api/v1/inventory/issues/{$id}/post");
        $postRes->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $je = $issue->journalEntry;

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(715977.71, $sumDebit);
        $this->assertEquals(715977.71, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'Debit and Credit must strictly balance under float amounts');
    }
}
