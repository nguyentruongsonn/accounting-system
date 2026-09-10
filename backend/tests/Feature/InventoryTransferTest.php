<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTransferTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected Warehouse $fromWarehouse;
    protected Warehouse $toWarehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->company = Company::create([
            'name' => 'Inventory Transfer Test Company',
            'tax_code' => '0101243199',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);
        $this->fromWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO_A',
            'name' => 'Kho A',
            'is_active' => true,
        ]);
        $this->toWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO_B',
            'name' => 'Kho B',
            'is_active' => true,
        ]);
        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'HH-TRANSFER-01',
            'name' => 'Hàng điều chuyển test',
            'type' => 'Goods',
            'is_active' => true,
        ]);
    }

    public function test_can_create_update_and_delete_a_draft_transfer(): void
    {
        $payload = $this->payload();
        $created = $this->postJson('/api/v1/inventory/transfers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_posted', false)
            ->assertJsonPath('data.lines.0.item_id', $this->item->id);

        $id = $created->json('data.id');
        $this->assertIsInt($id);
        $this->putJson("/api/v1/inventory/transfers/{$id}", [
            ...$payload,
            'description' => 'Đã cập nhật nháp',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 3,
                'unit' => 'thùng',
            ]],
        ])->assertOk()->assertJsonPath('data.description', 'Đã cập nhật nháp');

        $this->assertDatabaseHas('inventory_transfer_lines', [
            'inventory_transfer_id' => $id,
            'quantity' => 3,
        ]);
        $this->deleteJson("/api/v1/inventory/transfers/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa phiếu điều chuyển nháp.');
        $this->assertDatabaseMissing('inventory_transfers', ['id' => $id]);
    }

    public function test_rejects_same_warehouse_and_foreign_item_without_persisting(): void
    {
        $this->postJson('/api/v1/inventory/transfers', [
            ...$this->payload(),
            'to_warehouse_id' => $this->fromWarehouse->id,
        ])->assertStatus(422);

        $foreignCompany = Company::create(['name' => 'Foreign Inventory Company', 'tax_code' => '0101243200']);
        $foreignItem = Item::create([
            'company_id' => $foreignCompany->id,
            'code' => 'HH-FOREIGN',
            'name' => 'Hàng ngoài công ty',
            'type' => 'Goods',
            'is_active' => true,
        ]);
        $this->postJson('/api/v1/inventory/transfers', [
            ...$this->payload(),
            'lines' => [['item_id' => $foreignItem->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_foreign_transfer_cannot_be_read_or_deleted(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign Transfer Company', 'tax_code' => '0101243201']);
        $foreign = InventoryTransfer::create([
            'company_id' => $foreignCompany->id,
            'transfer_number' => 'DC-FOREIGN-01',
            'transfer_date' => '2026-08-26',
            'from_warehouse_id' => $this->fromWarehouse->id,
            'to_warehouse_id' => $this->toWarehouse->id,
            'status' => 'draft',
            'is_posted' => false,
        ]);

        $this->getJson("/api/v1/inventory/transfers/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/v1/inventory/transfers/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('inventory_transfers', ['id' => $foreign->id, 'company_id' => $foreignCompany->id]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'transfer_number' => 'DC-TEST-01',
            'transfer_date' => '2026-08-26',
            'from_warehouse_id' => $this->fromWarehouse->id,
            'to_warehouse_id' => $this->toWarehouse->id,
            'description' => 'Điều chuyển nội bộ test',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 2,
                'unit' => 'cái',
            ]],
        ];
    }
}
