<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\InventoryReceipt;
use App\Models\InventoryValuationRun;
use App\Models\Item;
use App\Models\User;
use App\Services\InventoryReceiptService;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TwoRolePermissions::seed();
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Test Company',
            'tax_code' => '123456789',
            'address' => 'Test Address',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '156', 'name' => 'Inventory', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1561', 'name' => 'Merchandise Purchase Cost', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '331', 'name' => 'Accounts Payable', 'type' => 'liability', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'IT001',
            'name' => 'Test Item',
            'type' => 'inventory',
            'inventory_account' => '156',
        ]);
    }

    public function test_can_create_inventory_receipt()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PN001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 10,
                    'unit_price' => 1000,
                    'credit_account' => '331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/inventory/receipts', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.voucher_number', 'PN001');

        $this->assertDatabaseHas('inventory_receipts', [
            'voucher_number' => 'PN001',
        ]);

        $this->assertDatabaseHas('inventory_receipt_lines', [
            'item_id' => $this->item->id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);
    }

    public function test_can_post_inventory_receipt_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PN002',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 10,
                    'unit_price' => 1000,
                    'credit_account' => '331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/inventory/receipts', $payload);
        $receiptId = $response->json('data.id');

        $postResponse = $this->postJson("/api/v1/inventory/receipts/{$receiptId}/post");
        $postResponse->assertStatus(200);

        $this->assertDatabaseHas('inventory_receipts', [
            'id' => $receiptId,
            'is_posted' => true,
        ]);

        $receipt = InventoryReceipt::find($receiptId);
        $this->assertNotNull($receipt->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $receipt->journal_entry_id,
            'status' => 'posted',
        ]);
    }

    public function test_posted_inventory_receipt_cannot_be_deleted_directly(): void
    {
        $response = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PN-DELETE-POSTED',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_price' => 1000,
                'credit_account' => '331',
            ]],
        ]);
        $id = $response->json('data.id');
        $this->postJson("/api/v1/inventory/receipts/{$id}/post")->assertOk();
        $journalId = InventoryReceipt::findOrFail($id)->journal_entry_id;

        $this->deleteJson("/api/v1/inventory/receipts/{$id}")
            ->assertStatus(409)
            ->assertJsonFragment(['error' => 'Không thể xóa phiếu nhập kho đã ghi sổ. Hãy bỏ ghi sổ hoặc hủy riêng trước khi xóa.']);

        $this->assertDatabaseHas('inventory_receipts', ['id' => $id, 'status' => 'posted']);
        $this->assertDatabaseHas('journal_entries', ['id' => $journalId, 'status' => 'posted']);
    }

    public function test_posting_a_backdated_receipt_invalidates_affected_valuation_runs(): void
    {
        $run = InventoryValuationRun::create([
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $response = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PN-INVALIDATE-RUN',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_price' => 1000,
                'credit_account' => '331',
            ]],
        ]);

        $response->assertCreated();
        $this->postJson('/api/v1/inventory/receipts/'.$response->json('data.id').'/post')->assertOk();

        $this->assertSame('invalidated', $run->fresh()->status);
        $this->assertSame('inventory_receipt_posted', $run->fresh()->invalidation_reason);
    }

    public function test_posting_uses_accounting_date_when_invalidating_valuation_runs(): void
    {
        $run = InventoryValuationRun::create([
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $receipt = app(InventoryReceiptService::class)->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PN-POSTING-DATE',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => '1.00',
                'unit_price' => '10.0000',
            ]],
        ]);

        app(InventoryReceiptService::class)->post($receipt->id, $this->company->id);

        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_update_resolves_each_line_item_inventory_account(): void
    {
        $secondItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'IT002',
            'name' => 'Second Test Item',
            'type' => 'inventory',
            'inventory_account' => '157',
        ]);

        $receipt = app(InventoryReceiptService::class)->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PN003',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);

        app(InventoryReceiptService::class)->update($receipt->id, [
            'company_id' => $this->company->id,
            'lines' => [
                ['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100],
                ['item_id' => $secondItem->id, 'quantity' => 1, 'unit_price' => 200],
            ],
        ]);

        $this->assertDatabaseHas('inventory_receipt_lines', [
            'inventory_receipt_id' => $receipt->id,
            'item_id' => $this->item->id,
            'debit_account' => '156',
        ]);
        $this->assertDatabaseHas('inventory_receipt_lines', [
            'inventory_receipt_id' => $receipt->id,
            'item_id' => $secondItem->id,
            'debit_account' => '157',
        ]);
    }

    public function test_line_amounts_preserve_exact_quantity_price_product_and_total(): void
    {
        $service = app(InventoryReceiptService::class);
        $method = new \ReflectionMethod($service, 'lineAmounts');
        $method->setAccessible(true);

        $amounts = $method->invoke($service, [
            'quantity' => '9007199254740.12',
            'unit_price' => '1234.5678',
        ]);

        $this->assertSame('9007199254740.12', $amounts['quantity']);
        $this->assertSame('1234.5678', $amounts['unit_price']);
        $this->assertSame('11119998168086149.52', $amounts['amount']);
    }
}
