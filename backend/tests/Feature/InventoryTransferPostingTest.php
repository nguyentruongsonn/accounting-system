<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Item;
use App\Models\OpeningBalanceInventoryLine;
use App\Models\OpeningBalancePackage;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryAvailabilityService;
use App\Services\StockReportService;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTransferPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_transfer_creates_balanced_warehouse_movements_without_a_journal_entry(): void
    {
        TwoRolePermissions::seed();
        $company = Company::create(['name' => 'Transfer posting company']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->assignRole('accountant');
        Sanctum::actingAs($actor);
        $fromWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'TRANSFER-FROM', 'name' => 'Kho xuất']);
        $toWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'TRANSFER-TO', 'name' => 'Kho nhận']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'TRANSFER-ITEM', 'name' => 'Hàng điều chuyển']);
        $opening = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create(['package_id' => $opening->id, 'item_id' => $item->id, 'warehouse_id' => $fromWarehouse->id, 'account_code' => '1561', 'quantity' => '5.0000', 'unit_cost' => '10.0000', 'total_value' => '50.00']);
        $transfer = InventoryTransfer::create([
            'company_id' => $company->id,
            'transfer_number' => 'TRANSFER-POST-001',
            'transfer_date' => '2026-01-15',
            'from_warehouse_id' => $fromWarehouse->id,
            'to_warehouse_id' => $toWarehouse->id,
            'status' => 'draft',
            'is_posted' => false,
        ]);
        $line = InventoryTransferLine::create([
            'inventory_transfer_id' => $transfer->id,
            'item_id' => $item->id,
            'quantity' => '2.000000',
        ]);

        $this->postJson('/api/v1/inventory/transfers/'.$transfer->id.'/post')
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.is_posted', true);

        $this->assertDatabaseHas('inventory_movement_events', [
            'company_id' => $company->id,
            'source_type' => InventoryTransfer::class,
            'source_id' => $transfer->id,
            'source_line_id' => $line->id,
            'warehouse_id' => $fromWarehouse->id,
            'movement_type' => 'transfer_out',
            'quantity_delta' => '-2.0000',
        ]);
        $this->assertDatabaseHas('inventory_movement_events', [
            'company_id' => $company->id,
            'source_type' => InventoryTransfer::class,
            'source_id' => $transfer->id,
            'source_line_id' => $line->id,
            'warehouse_id' => $toWarehouse->id,
            'movement_type' => 'transfer_in',
            'quantity_delta' => '2.0000',
        ]);
        $this->assertNull($transfer->fresh()->journal_entry_id ?? null);
        $availability = app(InventoryAvailabilityService::class);
        $this->assertSame('3.0000', $availability->availableQuantity($company->id, $item->id, $fromWarehouse->id, '2026-01-31'));
        $this->assertSame('2.0000', $availability->availableQuantity($company->id, $item->id, $toWarehouse->id, '2026-01-31'));

        $fromRow = collect(app(StockReportService::class)->generateReport($company->id, [
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'warehouse_id' => $fromWarehouse->id,
        ]))->sole();
        $toRow = collect(app(StockReportService::class)->generateReport($company->id, [
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'warehouse_id' => $toWarehouse->id,
        ]))->sole();
        $this->assertSame('3.0000', $fromRow['end_qty']);
        $this->assertSame('2.0000', $toRow['end_qty']);
    }

    public function test_only_admin_can_unpost_transfer_and_the_reversal_restores_both_warehouse_balances(): void
    {
        TwoRolePermissions::seed();
        $company = Company::create(['name' => 'Transfer unpost company']);
        $accountant = User::factory()->create(['company_id' => $company->id]);
        $accountant->assignRole('accountant');
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('admin');
        $fromWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'UNPOST-FROM', 'name' => 'Kho xuất']);
        $toWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'UNPOST-TO', 'name' => 'Kho nhận']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'UNPOST-ITEM', 'name' => 'Hàng điều chuyển']);
        $opening = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create(['package_id' => $opening->id, 'item_id' => $item->id, 'warehouse_id' => $fromWarehouse->id, 'account_code' => '1561', 'quantity' => '5.0000', 'unit_cost' => '10.0000', 'total_value' => '50.00']);
        $transfer = InventoryTransfer::create(['company_id' => $company->id, 'transfer_number' => 'TRANSFER-UNPOST-001', 'transfer_date' => '2026-01-15', 'from_warehouse_id' => $fromWarehouse->id, 'to_warehouse_id' => $toWarehouse->id, 'status' => 'draft', 'is_posted' => false]);
        InventoryTransferLine::create(['inventory_transfer_id' => $transfer->id, 'item_id' => $item->id, 'quantity' => '2.000000']);

        Sanctum::actingAs($accountant);
        $this->postJson('/api/v1/inventory/transfers/'.$transfer->id.'/post')->assertOk();
        $this->postJson('/api/v1/inventory/transfers/'.$transfer->id.'/unpost')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/inventory/transfers/'.$transfer->id.'/unpost')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_posted', false);

        $this->assertDatabaseHas('inventory_movement_events', ['source_id' => $transfer->id, 'warehouse_id' => $fromWarehouse->id, 'movement_type' => 'transfer_out_reversal', 'quantity_delta' => '2.0000']);
        $this->assertDatabaseHas('inventory_movement_events', ['source_id' => $transfer->id, 'warehouse_id' => $toWarehouse->id, 'movement_type' => 'transfer_in_reversal', 'quantity_delta' => '-2.0000']);
        $availability = app(InventoryAvailabilityService::class);
        $this->assertSame('5.0000', $availability->availableQuantity($company->id, $item->id, $fromWarehouse->id, '2026-01-31'));
        $this->assertSame('0.0000', $availability->availableQuantity($company->id, $item->id, $toWarehouse->id, '2026-01-31'));

        // A corrected draft can be posted again after an admin reversal. The
        // immutable movement history must not make the second posting collide
        // with the first posting's unique source identity.
        $this->postJson('/api/v1/inventory/transfers/'.$transfer->id.'/post')
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');
        $this->assertSame('3.0000', $availability->availableQuantity($company->id, $item->id, $fromWarehouse->id, '2026-01-31'));
        $this->assertSame('2.0000', $availability->availableQuantity($company->id, $item->id, $toWarehouse->id, '2026-01-31'));
    }
}
