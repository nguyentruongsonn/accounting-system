<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryStockCount;
use App\Models\Item;
use App\Models\OpeningBalanceInventoryLine;
use App\Models\OpeningBalancePackage;
use App\Models\User;
use App\Models\VoucherReference;
use App\Models\Warehouse;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\InventoryVoucherAccountMappingPostingGate;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryStockCountTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->company = Company::create([
            'name' => 'Inventory Stock Count Test Company',
            'tax_code' => '0101243299',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);
        TwoRolePermissions::seed();
        $this->user->assignRole('accountant');
        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-KK',
            'name' => 'Kho kiểm kê',
            'is_active' => true,
        ]);
        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'HH-KK-01',
            'name' => 'Hàng kiểm kê test',
            'type' => 'Goods',
            'is_active' => true,
        ]);
    }

    public function test_can_create_update_and_delete_a_draft_stock_count(): void
    {
        $payload = $this->payload();
        $created = $this->postJson('/api/v1/inventory/stock-counts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_posted', false)
            ->assertJsonPath('data.lines.0.item_id', $this->item->id)
            ->assertJsonPath('data.lines.0.counted_quantity', '0.000000');

        $id = $created->json('data.id');
        $this->assertIsInt($id);
        $this->putJson("/api/v1/inventory/stock-counts/{$id}", [
            ...$payload,
            'description' => 'Đã cập nhật biên bản nháp',
            'lines' => [[
                'item_id' => $this->item->id,
                'counted_quantity' => 4.5,
                'unit' => 'cái',
            ]],
        ])->assertOk()->assertJsonPath('data.description', 'Đã cập nhật biên bản nháp');

        $this->assertDatabaseHas('inventory_stock_count_lines', [
            'inventory_stock_count_id' => $id,
            'counted_quantity' => '4.500000',
        ]);
        $this->deleteJson("/api/v1/inventory/stock-counts/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa biên bản kiểm kê nháp.');
        $this->assertDatabaseMissing('inventory_stock_counts', ['id' => $id]);
    }

    public function test_rejects_duplicate_item_and_foreign_item_without_persisting(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/v1/inventory/stock-counts', [
            ...$payload,
            'lines' => [
                ['item_id' => $this->item->id, 'counted_quantity' => 1],
                ['item_id' => $this->item->id, 'counted_quantity' => 2],
            ],
        ])->assertStatus(422);

        $foreignCompany = Company::create(['name' => 'Foreign Stock Count Company', 'tax_code' => '0101243300']);
        $foreignItem = Item::create([
            'company_id' => $foreignCompany->id,
            'code' => 'HH-FOREIGN-KK',
            'name' => 'Hàng ngoài công ty',
            'type' => 'Goods',
            'is_active' => true,
        ]);
        $this->postJson('/api/v1/inventory/stock-counts', [
            ...$payload,
            'lines' => [['item_id' => $foreignItem->id, 'counted_quantity' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('inventory_stock_counts', 0);
    }

    public function test_foreign_stock_count_cannot_be_read_or_deleted(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign Count Company', 'tax_code' => '0101243301']);
        $foreign = InventoryStockCount::create([
            'company_id' => $foreignCompany->id,
            'count_number' => 'KK-FOREIGN-01',
            'count_date' => '2026-08-26',
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'is_posted' => false,
        ]);

        $this->getJson("/api/v1/inventory/stock-counts/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/v1/inventory/stock-counts/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('inventory_stock_counts', ['id' => $foreign->id, 'company_id' => $foreignCompany->id]);
    }

    public function test_variance_preview_uses_the_book_quantity_at_the_count_date_without_adjusting_stock(): void
    {
        $opening = OpeningBalancePackage::create([
            'company_id' => $this->company->id,
            'effective_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);
        OpeningBalanceInventoryLine::create([
            'package_id' => $opening->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'account_code' => '1561',
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
            'total_value' => '50.00',
        ]);
        $countId = $this->postJson('/api/v1/inventory/stock-counts', [
            ...$this->payload(),
            'lines' => [[
                'item_id' => $this->item->id,
                'counted_quantity' => 8,
                'unit' => 'cái',
            ]],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/inventory/stock-counts/{$countId}/variance")
            ->assertOk()
            ->assertJsonPath('data.count_id', $countId)
            ->assertJsonPath('data.lines.0.book_quantity', '10.0000')
            ->assertJsonPath('data.lines.0.counted_quantity', '8.000000')
            ->assertJsonPath('data.lines.0.variance_quantity', '-2.000000');

        $this->assertDatabaseCount('inventory_receipts', 0);
        $this->assertDatabaseCount('inventory_issues', 0);
    }

    public function test_creates_idempotent_adjustment_drafts_linked_to_the_count_without_posting_them(): void
    {
        $opening = OpeningBalancePackage::create([
            'company_id' => $this->company->id,
            'effective_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);
        OpeningBalanceInventoryLine::create([
            'package_id' => $opening->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'account_code' => '1561',
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
            'total_value' => '50.00',
        ]);
        $countId = $this->postJson('/api/v1/inventory/stock-counts', [
            ...$this->payload(),
            'lines' => [['item_id' => $this->item->id, 'counted_quantity' => 8, 'unit' => 'cái']],
        ])->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/inventory/stock-counts/{$countId}/adjustment-drafts")
            ->assertCreated()
            ->assertJsonPath('data.count_id', $countId)
            ->assertJsonCount(1, 'data.issue_drafts')
            ->assertJsonCount(0, 'data.receipt_drafts');
        $issueId = $response->json('data.issue_drafts.0.id');
        $this->assertIsInt($issueId);
        $this->assertDatabaseHas('inventory_issues', [
            'id' => $issueId,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'is_posted' => false,
        ]);
        $this->assertDatabaseHas('inventory_issue_lines', [
            'inventory_issue_id' => $issueId,
            'item_id' => $this->item->id,
            'quantity' => '2.00',
        ]);
        $this->assertDatabaseHas('voucher_references', [
            'source_type' => InventoryIssue::class,
            'source_id' => $issueId,
            'target_type' => InventoryStockCount::class,
            'target_id' => $countId,
        ]);
        $this->assertDatabaseCount('inventory_issues', 1);
        $this->assertDatabaseCount('inventory_receipts', 0);

        $this->postJson("/api/v1/inventory/stock-counts/{$countId}/adjustment-drafts")
            ->assertCreated()
            ->assertJsonPath('data.issue_drafts.0.id', $issueId);
        $this->assertSame(1, VoucherReference::where('target_type', InventoryStockCount::class)->where('target_id', $countId)->count());
        $this->assertDatabaseCount('inventory_issues', 1);

        $draft = InventoryIssue::with('lines')->findOrFail($issueId);
        $this->putJson("/api/v1/inventory/issues/{$issueId}", [
            'company_id' => $this->company->id,
            'voucher_type' => $draft->voucher_type,
            'voucher_number' => $draft->voucher_number,
            'voucher_date' => $draft->voucher_date->toDateString(),
            'posting_date' => $draft->posting_date->toDateString(),
            'warehouse_id' => $draft->warehouse_id,
            'description' => $draft->description,
            'referenced_vouchers' => $draft->referenced_vouchers,
            'lines' => $draft->lines->map(fn ($line): array => [
                'item_id' => $line->item_id,
                'warehouse_id' => $line->warehouse_id,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'amount' => $line->amount,
                'debit_account' => null,
                'credit_account' => null,
            ])->all(),
        ])->assertOk();
    }

    public function test_groups_multiple_variance_lines_into_one_draft_per_adjustment_direction(): void
    {
        $secondItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'HH-KK-02',
            'name' => 'Hàng kiểm kê test 2',
            'type' => 'Goods',
            'is_active' => true,
        ]);
        $opening = OpeningBalancePackage::create(['company_id' => $this->company->id, 'effective_date' => '2026-08-01', 'status' => 'confirmed']);
        foreach ([$this->item->id, $secondItem->id] as $itemId) {
            OpeningBalanceInventoryLine::create([
                'package_id' => $opening->id,
                'item_id' => $itemId,
                'warehouse_id' => $this->warehouse->id,
                'account_code' => '1561',
                'quantity' => '10.0000',
                'unit_cost' => '5.0000',
                'total_value' => '50.00',
            ]);
        }
        $countId = $this->postJson('/api/v1/inventory/stock-counts', [
            ...$this->payload(),
            'lines' => [
                ['item_id' => $this->item->id, 'counted_quantity' => 8],
                ['item_id' => $secondItem->id, 'counted_quantity' => 9],
            ],
        ])->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/inventory/stock-counts/{$countId}/adjustment-drafts")
            ->assertCreated()
            ->assertJsonCount(1, 'data.issue_drafts')
            ->assertJsonCount(0, 'data.receipt_drafts');
        $issueId = $response->json('data.issue_drafts.0.id');
        $this->assertDatabaseCount('inventory_issues', 1);
        $this->assertDatabaseCount('inventory_issue_lines', 2);
        $this->assertDatabaseHas('inventory_issue_lines', ['inventory_issue_id' => $issueId, 'item_id' => $this->item->id, 'quantity' => '2.00']);
        $this->assertDatabaseHas('inventory_issue_lines', ['inventory_issue_id' => $issueId, 'item_id' => $secondItem->id, 'quantity' => '1.00']);
    }

    public function test_count_adjustment_draft_can_follow_the_normal_inventory_update_and_post_gate(): void
    {
        $opening = OpeningBalancePackage::create([
            'company_id' => $this->company->id,
            'effective_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);
        OpeningBalanceInventoryLine::create([
            'package_id' => $opening->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'account_code' => '1561',
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
            'total_value' => '50.00',
        ]);
        $countId = $this->postJson('/api/v1/inventory/stock-counts', [
            ...$this->payload(),
            'lines' => [['item_id' => $this->item->id, 'counted_quantity' => 8, 'unit' => 'cái']],
        ])->assertCreated()->json('data.id');
        $issueId = $this->postJson("/api/v1/inventory/stock-counts/{$countId}/adjustment-drafts")
            ->assertCreated()
            ->json('data.issue_drafts.0.id');
        $draft = InventoryIssue::with('lines')->findOrFail($issueId);
        foreach (['632', '1561'] as $code) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => 'Stock-count '.$code,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $this->putJson("/api/v1/inventory/issues/{$issueId}", [
            'company_id' => $this->company->id,
            'voucher_type' => $draft->voucher_type,
            'voucher_number' => $draft->voucher_number,
            'voucher_date' => $draft->voucher_date->toDateString(),
            'posting_date' => $draft->posting_date->toDateString(),
            'warehouse_id' => $draft->warehouse_id,
            'description' => $draft->description,
            'referenced_vouchers' => $draft->referenced_vouchers,
            'lines' => $draft->lines->map(fn ($line): array => [
                'item_id' => $line->item_id,
                'warehouse_id' => $line->warehouse_id,
                'quantity' => $line->quantity,
                'unit_price' => '5.00',
                'amount' => '10.00',
                'debit_account' => '632',
                'credit_account' => '1561',
            ])->all(),
        ])->assertOk();

        $issue = $draft->fresh('lines');
        $this->createInventoryIssueMappingEvidence($issue, $issue->lines->first());

        $this->postJson("/api/v1/inventory/issues/{$issueId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.is_posted', true);
        $this->assertDatabaseHas('journal_entries', [
            'source_document_type' => InventoryIssue::class,
            'source_document_id' => $issueId,
            'status' => 'posted',
        ]);
    }

    private function createInventoryIssueMappingEvidence(InventoryIssue $issue, object $line): void
    {
        $checker = User::factory()->create(['company_id' => $this->company->id]);
        $checker->assignRole('admin');
        $year = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('year', 2026)
            ->firstOrFail();
        $policies = app(AccountingPolicyLifecycleService::class);
        $policy = $policies->approve($checker, $policies->createDraft($this->user, [
            'company_id' => $this->company->id,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.inventory_issue',
            'policy_version' => 'inventory-count-adjustment-v1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-approved-inventory-map'],
            'required_dimensions' => [],
            'regulatory_dependencies' => [],
        ]));
        $mappings = app(ApprovedAccountMappingLifecycleService::class);
        foreach (['debit' => '632', 'credit' => '1561'] as $role => $code) {
            $draft = $mappings->createDraft($this->user, [
                'company_id' => $this->company->id,
                'accounting_policy_version_id' => $policy->id,
                'mapping_key' => InventoryVoucherAccountMappingPostingGate::MAPPING_KEY,
                'mapping_context' => InventoryVoucherAccountMappingPostingGate::contextFor($issue, $line, $code),
                'account_role' => $role,
                'account_code' => $code,
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'regulatory_dependencies' => [],
            ]);
            $mappings->approve($checker, $draft);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'count_number' => 'KK-TEST-01',
            'count_date' => '2026-08-26',
            'warehouse_id' => $this->warehouse->id,
            'description' => 'Kiểm kê thực tế test',
            'lines' => [[
                'item_id' => $this->item->id,
                'counted_quantity' => 0,
                'unit' => 'cái',
            ]],
        ];
    }
}
