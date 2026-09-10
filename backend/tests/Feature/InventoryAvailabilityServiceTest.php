<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\Item;
use App\Models\OpeningBalanceInventoryLine;
use App\Models\OpeningBalancePackage;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryIssueService;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_quantity_uses_confirmed_opening_and_posted_line_movements_once(): void
    {
        $company = Company::create(['name' => 'Availability company']);
        $item = Item::create(['company_id' => $company->id, 'code' => 'AVAIL-ITEM', 'name' => 'Availability item', 'type' => 'Goods']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'AVAIL-WH', 'name' => 'Availability warehouse']);
        $opening = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create(['package_id' => $opening->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'account_code' => '1561', 'quantity' => '2.0000', 'unit_cost' => '10.0000', 'total_value' => '20.00']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'voucher_number' => 'AVAIL-R', 'voucher_date' => '2026-01-10', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '3.00', 'amount' => '30.00']);
        $issue = InventoryIssue::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'voucher_number' => 'AVAIL-I', 'voucher_date' => '2026-01-20', 'is_posted' => true]);
        InventoryIssueLine::create(['inventory_issue_id' => $issue->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '1.00', 'amount' => '10.00']);

        $available = app(InventoryAvailabilityService::class)->availableQuantity($company->id, $item->id, $warehouse->id, '2026-01-31');

        $this->assertSame('4.0000', $available);
    }

    public function test_available_quantity_uses_posting_date_with_voucher_date_only_as_legacy_fallback(): void
    {
        $company = Company::create(['name' => 'Availability posting-date company']);
        $item = Item::create(['company_id' => $company->id, 'code' => 'AVAIL-POSTING-ITEM', 'name' => 'Posting-date item', 'type' => 'Goods']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'AVAIL-POSTING-WH', 'name' => 'Posting-date warehouse']);
        $opening = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create(['package_id' => $opening->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'account_code' => '1561', 'quantity' => '2.0000', 'unit_cost' => '10.0000', 'total_value' => '20.00']);
        $receipt = InventoryReceipt::create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'voucher_number' => 'AVAIL-POSTING-R',
            'voucher_date' => '2026-01-10',
            'posting_date' => '2026-02-10',
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '3.00', 'amount' => '30.00']);

        $beforePosting = app(InventoryAvailabilityService::class)->availableQuantity($company->id, $item->id, $warehouse->id, '2026-01-31');
        $afterPosting = app(InventoryAvailabilityService::class)->availableQuantity($company->id, $item->id, $warehouse->id, '2026-02-28');

        $this->assertSame('2.0000', $beforePosting);
        $this->assertSame('5.0000', $afterPosting);
    }

    public function test_issue_posting_rejects_quantity_above_available_stock_in_the_line_warehouse(): void
    {
        TwoRolePermissions::seed();
        config()->set('accounting.enforce_inventory_stock_availability', true);
        $actor = User::factory()->create(['company_id' => 1]);
        $actor->assignRole('accountant');
        Sanctum::actingAs($actor);
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        foreach (['632', '1561'] as $code) {
            ChartOfAccount::create(['company_id' => 1, 'code' => $code, 'name' => "Account {$code}", 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
        $item = Item::create(['company_id' => 1, 'code' => 'NO-OVERSELL', 'name' => 'No oversell item', 'type' => 'Goods']);
        $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'NO-OVERSELL-WH', 'name' => 'No oversell warehouse']);
        $issue = InventoryIssue::create(['company_id' => 1, 'warehouse_id' => $warehouse->id, 'voucher_number' => 'NO-OVERSELL-I', 'voucher_date' => '2026-01-10', 'posting_date' => '2026-01-10', 'total_amount' => '10.00', 'status' => 'draft', 'is_posted' => false]);
        InventoryIssueLine::create(['inventory_issue_id' => $issue->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00', 'debit_account' => '632', 'credit_account' => '1561']);

        try {
            app(InventoryIssueService::class)->post($issue->id);
            $this->fail('A posted inventory issue cannot exceed available stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.quantity', $exception->errors());
        }
        $this->assertDatabaseHas('inventory_issues', ['id' => $issue->id, 'status' => 'draft', 'is_posted' => false, 'journal_entry_id' => null]);
    }
}
