<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryValuationRun;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryIssueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Warehouse $warehouse;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Employee $employee;

    protected Item $goodsItem;

    protected Item $rawMaterialItem;

    protected Item $toolItem;

    protected Item $finishedProductItem;

    protected function setUp(): void
    {
        parent::setUp();
        // This legacy suite exercises GL account selection, not stock
        // availability. Dedicated availability tests keep the production
        // stock control enabled.
        config()->set('accounting.enforce_inventory_stock_availability', false);
        TwoRolePermissions::seed();

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Công ty TNHH Thử Nghiệm Kho MISA',
                'tax_code' => '0109876543',
                'address' => 'Tầng 5 Tòa nhà Công Nghệ, Cầu Giấy, Hà Nội',
                'is_active' => true,
            ]
        );

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Thủ kho kiêm Kế toán MISA',
            'email' => 'inventory_test_'.uniqid().'@example.com',
        ]);
        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);

        $this->fiscalYear = FiscalYear::firstOrCreate(
            ['id' => 1],
            [
                'company_id' => $this->company->id,
                'name' => 'Năm tài chính 2026',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
                'is_closed' => false,
            ]
        );

        // Chart of Accounts for VAS TT200
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '153', 'name' => 'Công cụ, dụng cụ', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '154', 'name' => 'Chi phí sản xuất kinh doanh dở dang', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '155', 'name' => 'Thành phẩm', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1561', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '621', 'name' => 'Chi phí nguyên vật liệu trực tiếp', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit'],
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
            ['code' => 'KHO_TONG'],
            [
                'company_id' => $this->company->id,
                'name' => 'Kho Tổng Hà Nội',
                'account_code' => '1561',
            ]
        );

        $this->customer = Customer::firstOrCreate(
            ['code' => 'KH_ISSUE_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Công ty Khách Hàng Xuất Kho',
                'tax_code' => '0101112223',
                'address' => 'Hà Nội',
            ]
        );

        $this->supplier = Supplier::firstOrCreate(
            ['code' => 'NCC_ISSUE_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Nhà Cung Cấp Linh Kiện',
                'tax_code' => '0103334445',
                'address' => 'Bắc Ninh',
            ]
        );

        $this->employee = Employee::firstOrCreate(
            ['code' => 'NV_ISSUE_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Nguyễn Văn Xuất',
                'department' => 'Phòng Kho Vận',
            ]
        );

        $this->goodsItem = Item::firstOrCreate(
            ['code' => 'HH_DELL_G15'],
            [
                'company_id' => $this->company->id,
                'name' => 'Laptop Dell Gaming G15',
                'unit' => 'Chiếc',
                'purchase_price' => 18000000,
                'selling_price' => 23000000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'type' => 'Goods',
            ]
        );

        $this->rawMaterialItem = Item::firstOrCreate(
            ['code' => 'NVL_THEP_CUON'],
            [
                'company_id' => $this->company->id,
                'name' => 'Thép cuộn mạ kẽm',
                'unit' => 'Kg',
                'purchase_price' => 25000,
                'selling_price' => 32000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '152',
                'type' => 'RawMaterial',
            ]
        );

        $this->toolItem = Item::firstOrCreate(
            ['code' => 'CCDC_MAY_KHOAN'],
            [
                'company_id' => $this->company->id,
                'name' => 'Máy khoan bê tông Bosch',
                'unit' => 'Bộ',
                'purchase_price' => 2500000,
                'selling_price' => 3000000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '153',
                'type' => 'Tool',
            ]
        );

        $this->finishedProductItem = Item::firstOrCreate(
            ['code' => 'TP_BAN_LAM_VIEC'],
            [
                'company_id' => $this->company->id,
                'name' => 'Bàn làm việc văn phòng Hòa Phát',
                'unit' => 'Cái',
                'purchase_price' => 1200000,
                'selling_price' => 1800000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '155',
                'type' => 'FinishedGoods',
            ]
        );
    }

    public function test_can_create_inventory_issue(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Xuất kho bán hàng',
            'contact_type' => 'customer',
            'contact_id' => $this->customer->id,
            'contact_name' => $this->customer->name,
            'receiver_name' => 'Nguyễn Văn Nhận',
            'receiver_address' => 'Hà Nội',
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->name,
            'warehouse_id' => $this->warehouse->id,
            'voucher_number' => 'PXK-2026-0001',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Xuất kho bán máy tính Dell G15',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'warehouse_id' => $this->warehouse->id,
                    'warehouse_code' => $this->warehouse->code,
                    'quantity' => 5,
                    'unit_price' => 18000000,
                    'amount' => 90000000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                    'description' => 'Xuất bán 5 máy Dell G15',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/inventory/issues', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.voucher_number', 'PXK-2026-0001')
            ->assertJsonPath('data.total_amount', 90000000);

        $this->assertDatabaseHas('inventory_issues', [
            'voucher_number' => 'PXK-2026-0001',
            'total_amount' => 90000000,
            'is_posted' => false,
        ]);

        $this->assertDatabaseHas('inventory_issue_lines', [
            'item_id' => $this->goodsItem->id,
            'quantity' => 5,
            'unit_price' => 18000000,
            'amount' => 90000000,
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);
    }

    public function test_can_list_inventory_issues_with_filters(): void
    {
        InventoryIssue::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FILTER-01',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'description' => 'Phiếu xuất kho tìm kiếm Alpha',
            'total_amount' => 10000000,
            'is_posted' => false,
        ]);

        InventoryIssue::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FILTER-02',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'description' => 'Phiếu xuất kho tìm kiếm Beta',
            'total_amount' => 20000000,
            'is_posted' => false,
        ]);

        $res = $this->getJson("/api/v1/inventory/issues?company_id={$this->company->id}&search=Alpha");
        $res->assertStatus(200);
        $this->assertTrue(collect($res->json('data'))->contains('voucher_number', 'PXK-FILTER-01'));
        $this->assertFalse(collect($res->json('data'))->contains('voucher_number', 'PXK-FILTER-02'));

        $dateRes = $this->getJson("/api/v1/inventory/issues?company_id={$this->company->id}&from_date=2026-08-15&to_date=2026-08-25");
        $dateRes->assertStatus(200);
        $this->assertTrue(collect($dateRes->json('data'))->contains('voucher_number', 'PXK-FILTER-02'));
    }

    public function test_can_get_single_inventory_issue_details(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-SHOW-01',
            'voucher_date' => '2026-08-15',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 2,
                    'unit_price' => 18000000,
                    'amount' => 36000000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $showRes = $this->getJson("/api/v1/inventory/issues/{$id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.voucher_number', 'PXK-SHOW-01')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.amount', 36000000);
    }

    public function test_can_update_unposted_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-EDIT-01',
            'voucher_date' => '2026-08-15',
            'description' => 'Mô tả ban đầu',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 2,
                    'unit_price' => 18000000,
                    'amount' => 36000000,
                ],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $updatePayload = [
            'description' => 'Mô tả đã được cập nhật mới',
            'receiver_name' => 'Nguyễn Thị Cập Nhật',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 4,
                    'unit_price' => 18000000,
                    'amount' => 72000000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ];

        $updateRes = $this->putJson("/api/v1/inventory/issues/{$id}", $updatePayload);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.total_amount', 72000000)
            ->assertJsonPath('data.description', 'Mô tả đã được cập nhật mới');

        $this->assertDatabaseHas('inventory_issues', [
            'id' => $id,
            'description' => 'Mô tả đã được cập nhật mới',
            'total_amount' => 72000000,
        ]);
    }

    public function test_cannot_update_posted_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-LOCKED-01',
            'voucher_date' => '2026-08-15',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 1,
                    'unit_price' => 18000000,
                    'amount' => 18000000,
                ],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        // Post to GL
        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        // Attempt update
        $updateRes = $this->putJson("/api/v1/inventory/issues/{$id}", [
            'description' => 'Cố tình sửa chứng từ đã ghi sổ',
        ]);

        $updateRes->assertStatus(409)
            ->assertJsonFragment(['error' => 'Không thể sửa phiếu xuất kho đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.']);
    }

    public function test_can_delete_unposted_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-DEL-01',
            'voucher_date' => '2026-08-15',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 1,
                    'unit_price' => 18000000,
                    'amount' => 18000000,
                ],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $delRes = $this->deleteJson("/api/v1/inventory/issues/{$id}");
        $delRes->assertStatus(200)
            ->assertJsonPath('message', 'Deleted successfully');

        $this->assertDatabaseMissing('inventory_issues', ['id' => $id]);
        $this->assertDatabaseMissing('inventory_issue_lines', ['inventory_issue_id' => $id]);
    }

    public function test_cannot_delete_posted_inventory_issue_directly(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-DEL-POSTED',
            'voucher_date' => '2026-08-15',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 2,
                    'unit_price' => 18000000,
                    'amount' => 36000000,
                ],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);
        $issue = InventoryIssue::find($id);
        $jeId = $issue->journal_entry_id;

        $this->deleteJson("/api/v1/inventory/issues/{$id}")
            ->assertStatus(409)
            ->assertJsonFragment(['error' => 'Không thể xóa phiếu xuất kho đã ghi sổ. Hãy bỏ ghi sổ hoặc hủy riêng trước khi xóa.']);

        $this->assertDatabaseHas('inventory_issues', ['id' => $id, 'status' => 'posted', 'journal_entry_id' => $jeId]);
        $this->assertDatabaseHas('journal_entries', ['id' => $jeId, 'status' => 'posted']);
    }

    public function test_can_post_inventory_issue_to_gl(): void
    {
        $run = InventoryValuationRun::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-POST-01',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 2,
                    'unit_price' => 18000000,
                    'amount' => 36000000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $postRes = $this->postJson("/api/v1/inventory/issues/{$id}/post");
        $postRes->assertStatus(200)
            ->assertJsonPath('data.is_posted', true);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $this->assertTrue($issue->is_posted);
        $this->assertNotNull($issue->journal_entry_id);

        $je = $issue->journalEntry;
        $this->assertEquals('posted', $je->status);
        $this->assertEquals(36000000, $je->total_amount);

        $debit632 = $je->lines->firstWhere('account_code', '632');
        $credit1561 = $je->lines->firstWhere('account_code', '1561');

        $this->assertEquals(36000000, $debit632->debit_amount);
        $this->assertEquals(36000000, $credit1561->credit_amount);
        $this->assertEquals($debit632->debit_amount, $credit1561->credit_amount);
        $this->assertSame('invalidated', $run->fresh()->status);
        $this->assertSame('inventory_issue_posted', $run->fresh()->invalidation_reason);
    }

    public function test_issue_posting_uses_accounting_date_when_invalidating_valuation_runs(): void
    {
        $run = InventoryValuationRun::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-POSTING-DATE',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $this->goodsItem->id,
                'quantity' => '1.00',
                'unit_price' => '10.0000',
                'amount' => '10.00',
                'debit_account' => '632',
                'credit_account' => '1561',
            ]],
        ]);
        $createRes->assertCreated();
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertOk();

        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_cannot_double_post_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-DBL-POST',
            'voucher_date' => '2026-08-15',
            'lines' => [
                ['item_id' => $this->goodsItem->id, 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);
        $repostRes = $this->postJson("/api/v1/inventory/issues/{$id}/post");
        $repostRes->assertStatus(400)
            ->assertJsonFragment(['error' => 'Voucher is already posted']);
    }

    public function test_can_unpost_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-UNPOST-01',
            'voucher_date' => '2026-08-15',
            'lines' => [
                ['item_id' => $this->goodsItem->id, 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);
        $this->assertTrue(InventoryIssue::find($id)->is_posted);

        $unpostRes = $this->postJson("/api/v1/inventory/issues/{$id}/unpost");
        $unpostRes->assertStatus(200);

        $issue = InventoryIssue::find($id);
        $this->assertFalse($issue->is_posted);

        $je = JournalEntry::find($issue->journal_entry_id);
        $this->assertEquals('voided', $je->status);
    }

    public function test_can_void_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-VOID-01',
            'voucher_date' => '2026-08-15',
            'lines' => [
                ['item_id' => $this->goodsItem->id, 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $voidRes = $this->postJson("/api/v1/inventory/issues/{$id}/void");
        $voidRes->assertStatus(200);

        $this->assertFalse(InventoryIssue::find($id)->is_posted);
    }

    public function test_cannot_void_unposted_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-VOID-FAIL',
            'voucher_date' => '2026-08-15',
            'lines' => [
                ['item_id' => $this->goodsItem->id, 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $voidRes = $this->postJson("/api/v1/inventory/issues/{$id}/void");
        $voidRes->assertStatus(400)
            ->assertJsonFragment(['error' => 'Voucher is not posted yet']);
    }

    public function test_can_duplicate_inventory_issue(): void
    {
        $createRes = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-ORIG-01',
            'voucher_date' => '2026-08-15',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 3,
                    'unit_price' => 18000000,
                    'amount' => 54000000,
                ],
            ],
        ]);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $dupRes = $this->postJson("/api/v1/inventory/issues/{$id}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('data.id') ?? $dupRes->json('id');

        $this->assertNotEquals($id, $dupId);
        $dupIssue = InventoryIssue::with('lines')->find($dupId);
        $this->assertNotEquals('PXK-ORIG-01', $dupIssue->voucher_number);
        $this->assertEquals('draft', $dupIssue->status);
        $this->assertFalse($dupIssue->is_posted);
        $this->assertNull($dupIssue->journal_entry_id);
        $this->assertCount(1, $dupIssue->lines);
        $this->assertEquals(54000000, $dupIssue->total_amount);
    }

    public function test_can_generate_next_code(): void
    {
        $res = $this->getJson('/api/v1/inventory/issues/next-code');
        $res->assertStatus(200);
        $code = $res->json('code') ?? $res->json('data');
        $this->assertStringStartsWith('PXK-2026-', $code);

        $customPrefixRes = $this->getJson('/api/v1/inventory/issues/next-code?prefix=XKNB');
        $customPrefixRes->assertStatus(200);
        $customCode = $customPrefixRes->json('code') ?? $customPrefixRes->json('data');
        $this->assertStringStartsWith('XKNB-2026-', $customCode);
    }

    public function test_gl_posting_reason_sales_issue_632_vs_1561(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Xuất kho bán hàng',
            'voucher_number' => 'PXK-SALES-01',
            'voucher_date' => '2026-08-16',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 5,
                    'unit_price' => 18000000,
                    'amount' => 90000000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $id = $createRes->json('data.id') ?? $createRes->json('id');
        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $je = $issue->journalEntry;

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(90000000, $sumDebit);
        $this->assertEquals(90000000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'Total Debit must strictly equal Total Credit');

        $this->assertEquals(90000000, $je->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(90000000, $je->lines->firstWhere('account_code', '1561')->credit_amount);
    }

    public function test_gl_posting_reason_production_issue_621_vs_152(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => '2. Xuất kho sản xuất',
            'voucher_number' => 'PXK-PROD-01',
            'voucher_date' => '2026-08-16',
            'lines' => [
                [
                    'item_id' => $this->rawMaterialItem->id,
                    'quantity' => 200,
                    'unit_price' => 25000,
                    'amount' => 5000000,
                    'debit_account' => '621',
                    'credit_account' => '152',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $id = $createRes->json('data.id') ?? $createRes->json('id');
        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $je = $issue->journalEntry;

        $this->assertEquals(5000000, $je->lines->sum('debit_amount'));
        $this->assertEquals(5000000, $je->lines->sum('credit_amount'));
        $this->assertEquals(5000000, $je->lines->firstWhere('account_code', '621')->debit_amount);
        $this->assertEquals(5000000, $je->lines->firstWhere('account_code', '152')->credit_amount);
    }

    public function test_gl_posting_reason_tool_issue_642_vs_153(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => '3. Xuất kho CCDC',
            'voucher_number' => 'PXK-TOOL-01',
            'voucher_date' => '2026-08-16',
            'lines' => [
                [
                    'item_id' => $this->toolItem->id,
                    'quantity' => 2,
                    'unit_price' => 2500000,
                    'amount' => 5000000,
                    'debit_account' => '642',
                    'credit_account' => '153',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $id = $createRes->json('data.id') ?? $createRes->json('id');
        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $je = $issue->journalEntry;

        $this->assertEquals(5000000, $je->lines->sum('debit_amount'));
        $this->assertEquals(5000000, $je->lines->sum('credit_amount'));
        $this->assertEquals(5000000, $je->lines->firstWhere('account_code', '642')->debit_amount);
        $this->assertEquals(5000000, $je->lines->firstWhere('account_code', '153')->credit_amount);
    }

    public function test_gl_posting_reason_other_expense_811_vs_1561(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => '4. Xuất kho khác',
            'voucher_number' => 'PXK-OTHER-01',
            'voucher_date' => '2026-08-16',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 1,
                    'unit_price' => 18000000,
                    'amount' => 18000000,
                    'debit_account' => '811',
                    'credit_account' => '1561',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $id = $createRes->json('data.id') ?? $createRes->json('id');
        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $je = $issue->journalEntry;

        $this->assertEquals(18000000, $je->lines->sum('debit_amount'));
        $this->assertEquals(18000000, $je->lines->sum('credit_amount'));
        $this->assertEquals(18000000, $je->lines->firstWhere('account_code', '811')->debit_amount);
        $this->assertEquals(18000000, $je->lines->firstWhere('account_code', '1561')->credit_amount);
    }

    public function test_gl_posting_finished_goods_issue_632_vs_155(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Xuất kho bán hàng',
            'voucher_number' => 'PXK-FG-01',
            'voucher_date' => '2026-08-16',
            'lines' => [
                [
                    'item_id' => $this->finishedProductItem->id,
                    'quantity' => 10,
                    'unit_price' => 1200000,
                    'amount' => 12000000,
                    'debit_account' => '632',
                    'credit_account' => '155',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $id = $createRes->json('data.id') ?? $createRes->json('id');
        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $je = $issue->journalEntry;

        $this->assertEquals(12000000, $je->lines->sum('debit_amount'));
        $this->assertEquals(12000000, $je->lines->sum('credit_amount'));
        $this->assertEquals(12000000, $je->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(12000000, $je->lines->firstWhere('account_code', '155')->credit_amount);
    }

    public function test_gl_posting_multi_line_mixed_reasons_strict_balance(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'Xuất kho tổng hợp nhiều đối tượng',
            'voucher_number' => 'PXK-MIXED-01',
            'voucher_date' => '2026-08-17',
            'lines' => [
                [
                    'item_id' => $this->goodsItem->id,
                    'quantity' => 2,
                    'unit_price' => 18000000,
                    'amount' => 36000000,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
                [
                    'item_id' => $this->rawMaterialItem->id,
                    'quantity' => 100,
                    'unit_price' => 25000,
                    'amount' => 2500000,
                    'debit_account' => '621',
                    'credit_account' => '152',
                ],
                [
                    'item_id' => $this->toolItem->id,
                    'quantity' => 1,
                    'unit_price' => 2500000,
                    'amount' => 2500000,
                    'debit_account' => '642',
                    'credit_account' => '153',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $id = $createRes->json('data.id') ?? $createRes->json('id');
        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $this->assertEquals(41000000, $issue->total_amount);

        $je = $issue->journalEntry;
        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(41000000, $sumDebit);
        $this->assertEquals(41000000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'Total Debit must equal Total Credit');

        $this->assertEquals(36000000, $je->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(36000000, $je->lines->firstWhere('account_code', '1561')->credit_amount);
        $this->assertEquals(2500000, $je->lines->firstWhere('account_code', '621')->debit_amount);
        $this->assertEquals(2500000, $je->lines->firstWhere('account_code', '152')->credit_amount);
        $this->assertEquals(2500000, $je->lines->firstWhere('account_code', '642')->debit_amount);
        $this->assertEquals(2500000, $je->lines->firstWhere('account_code', '153')->credit_amount);
    }

    public function test_voucher_references_and_search(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-REF-01',
            'voucher_date' => '2026-08-18',
            'lines' => [
                ['item_id' => $this->goodsItem->id, 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
            ],
            'referenced_vouchers' => [
                [
                    'voucher_type' => 'Đơn đặt hàng bán',
                    'voucher_number' => 'DDH-2026-0099',
                    'voucher_date' => '2026-08-10',
                    'total_amount' => 1000000,
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/inventory/issues', $payload);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id') ?? $createRes->json('id');

        $issue = InventoryIssue::with('references')->find($id);
        $this->assertCount(1, $issue->references);
        $this->assertEquals('DDH-2026-0099', $issue->references[0]->target_voucher_number);

        $searchRes = $this->getJson('/api/v1/voucher-references/search?module_group=inventory&keyword=PXK-REF-01');
        $searchRes->assertStatus(200);
        $this->assertTrue(collect($searchRes->json('data'))->contains('voucher_number', 'PXK-REF-01'));
    }
}
