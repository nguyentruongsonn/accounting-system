<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Item $itemA;

    private Item $itemB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Inventory A', 'tax_code' => 'INV-A']);
        $this->companyB = Company::create(['name' => 'Inventory B', 'tax_code' => 'INV-B']);
        $this->userA = User::factory()->create();
        $this->configureAccountingTenant($this->userA, $this->companyA);
        Sanctum::actingAs($this->userA);

        foreach (['152', '1561', '331', '632'] as $code) {
            ChartOfAccount::withoutGlobalScopes()->create([
                'company_id' => $this->companyA->id,
                'code' => $code,
                'name' => "A $code",
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
            ]);
            ChartOfAccount::withoutGlobalScopes()->create([
                'company_id' => $this->companyB->id,
                'code' => "B$code",
                'name' => "B $code",
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
            ]);
        }

        $this->warehouseA = Warehouse::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id,
            'code' => 'WH-A',
            'name' => 'Warehouse A',
            'is_active' => true,
        ]);
        $this->warehouseB = Warehouse::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'code' => 'WH-B',
            'name' => 'Warehouse B',
            'is_active' => true,
        ]);
        $this->itemA = Item::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id,
            'code' => 'ITEM-A',
            'name' => 'Item A',
            'type' => 'goods',
            'unit' => 'pcs',
        ]);
        $this->itemB = Item::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'code' => 'ITEM-B',
            'name' => 'Item B',
            'type' => 'goods',
            'unit' => 'pcs',
        ]);
    }

    public function test_inventory_lists_show_and_next_codes_ignore_client_company(): void
    {
        [$receiptA, $receiptB, $issueA, $issueB] = $this->documents();

        $this->getJson('/api/v1/inventory/items?company_id='.$this->companyB->id)
            ->assertOk()->assertJsonFragment(['code' => 'ITEM-A'])->assertJsonMissing(['code' => 'ITEM-B']);
        $this->getJson('/api/v1/master/warehouses?company_id='.$this->companyB->id)
            ->assertOk()->assertJsonFragment(['code' => 'WH-A'])->assertJsonMissing(['code' => 'WH-B']);

        foreach ([
            ['/api/v1/inventory/receipts', $receiptA, $receiptB],
            ['/api/v1/inventory/issues', $issueA, $issueB],
        ] as [$endpoint, $owned, $foreign]) {
            $response = $this->getJson($endpoint.'?company_id='.$this->companyB->id)->assertOk();
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($owned->id));
            $this->assertFalse($ids->contains($foreign->id));
            $this->getJson($endpoint.'/'.$foreign->id)->assertNotFound();
        }

        $this->getJson('/api/v1/inventory/items/'.$this->itemB->id)->assertNotFound();
        $this->getJson('/api/v1/master/warehouses/'.$this->warehouseB->id)->assertNotFound();
        $this->getJson('/api/v1/inventory/receipts?warehouse_id='.$this->warehouseB->id)->assertUnprocessable();
        $this->getJson('/api/v1/inventory/issues?warehouse_id='.$this->warehouseB->id)->assertUnprocessable();
        $this->getJson('/api/v1/inventory/receipts/next-code?company_id='.$this->companyB->id)
            ->assertOk()->assertJsonPath('code', 'PNK-'.now()->format('Y').'-0002');
        $this->getJson('/api/v1/inventory/issues/next-code?company_id='.$this->companyB->id)
            ->assertOk()->assertJsonPath('code', 'PXK-'.now()->format('Y').'-0002');
    }

    public function test_malicious_company_is_overwritten_and_foreign_references_are_rejected(): void
    {
        $this->postJson('/api/v1/inventory/items', [
            'company_id' => $this->companyB->id,
            'code' => 'ITEM-CREATED-A',
            'name' => 'Created in A',
        ])->assertCreated();
        $this->assertDatabaseHas('items', ['code' => 'ITEM-CREATED-A', 'company_id' => $this->companyA->id]);

        $this->postJson('/api/v1/master/warehouses', [
            'company_id' => $this->companyB->id,
            'code' => 'WH-CREATED-A',
            'name' => 'Created in A',
        ])->assertCreated();
        $this->assertDatabaseHas('warehouses', ['code' => 'WH-CREATED-A', 'company_id' => $this->companyA->id]);

        $valid = [
            'company_id' => $this->companyB->id,
            'voucher_number' => 'PNK-MALICIOUS',
            'warehouse_id' => $this->warehouseA->id,
            'lines' => [[
                'item_id' => $this->itemA->id,
                'warehouse_id' => $this->warehouseA->id,
                'quantity' => 1,
                'unit_price' => 100,
                'debit_account' => '1561',
                'credit_account' => '331',
            ]],
        ];
        $this->postJson('/api/v1/inventory/receipts', $valid)->assertCreated();
        $this->assertDatabaseHas('inventory_receipts', [
            'voucher_number' => 'PNK-MALICIOUS',
            'company_id' => $this->companyA->id,
        ]);

        foreach ([
            ['warehouse_id' => $this->warehouseB->id],
            ['lines.0.item_id' => $this->itemB->id],
            ['lines.0.warehouse_id' => $this->warehouseB->id],
            ['lines.0.credit_account' => 'B331'],
        ] as $attack) {
            $payload = $valid;
            foreach ($attack as $path => $value) {
                data_set($payload, $path, $value);
            }
            $payload['voucher_number'] = 'ATTACK-'.md5(json_encode($attack));
            $this->postJson('/api/v1/inventory/receipts', $payload)->assertUnprocessable();
            $this->postJson('/api/v1/inventory/issues', $payload)->assertUnprocessable();
        }
    }

    public function test_inventory_document_mutations_scope_missing_and_forged_company_ids_to_the_actor_before_validation(): void
    {
        foreach ([
            ['receipts', 'PNK-TENANT-CONTEXT', 'Receipt'],
            ['issues', 'PXK-TENANT-CONTEXT', 'Issue'],
        ] as [$resource, $voucherNumber, $label]) {
            $create = $this->postJson("/api/v1/inventory/{$resource}", [
                'voucher_number' => $voucherNumber,
                'warehouse_id' => $this->warehouseA->id,
                'lines' => [[
                    'item_id' => $this->itemA->id,
                    'warehouse_id' => $this->warehouseA->id,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ]],
            ])->assertCreated();

            $id = $create->json('data.id');
            $this->assertNotNull($id, "{$label} creation must return its persisted ID.");
            $this->assertDatabaseHas("inventory_{$resource}", [
                'id' => $id,
                'company_id' => $this->companyA->id,
            ]);

            $this->putJson("/api/v1/inventory/{$resource}/{$id}", [
                'company_id' => $this->companyB->id,
                'description' => "{$label} remains in the actor company",
            ])->assertOk();

            $this->assertDatabaseHas("inventory_{$resource}", [
                'id' => $id,
                'company_id' => $this->companyA->id,
                'description' => "{$label} remains in the actor company",
            ]);
        }
    }

    public function test_inventory_receipt_and_issue_mutation_routes_include_the_transactional_tenant_contract(): void
    {
        foreach ([
            ['POST', '/api/v1/inventory/receipts'],
            ['PUT', '/api/v1/inventory/receipts/1'],
            ['POST', '/api/v1/inventory/issues'],
            ['PUT', '/api/v1/inventory/issues/1'],
        ] as [$method, $uri]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));

            $this->assertContains(
                'transactional_tenant',
                $route->gatherMiddleware(),
                "{$method} {$uri} must establish the authenticated tenant before its FormRequest validates."
            );
        }
    }

    public function test_foreign_route_ids_cannot_be_mutated_by_inventory_lifecycle(): void
    {
        [, $receiptB, , $issueB] = $this->documents();

        foreach ([
            ['/api/v1/inventory/receipts', $receiptB],
            ['/api/v1/inventory/issues', $issueB],
        ] as [$endpoint, $foreign]) {
            foreach (['post', 'void', 'unpost', 'duplicate'] as $action) {
                $this->assertGreaterThanOrEqual(400, $this->postJson("$endpoint/{$foreign->id}/$action")->status());
            }
            $this->assertGreaterThanOrEqual(400, $this->putJson("$endpoint/{$foreign->id}", ['description' => 'attack'])->status());
            $this->assertGreaterThanOrEqual(400, $this->deleteJson("$endpoint/{$foreign->id}")->status());
            $this->assertNotNull($foreign::withoutGlobalScopes()->find($foreign->id));
        }

        $this->putJson('/api/v1/inventory/items/'.$this->itemB->id, ['name' => 'attack'])->assertNotFound();
        $this->deleteJson('/api/v1/inventory/items/'.$this->itemB->id)->assertNotFound();
        $this->putJson('/api/v1/master/warehouses/'.$this->warehouseB->id, ['name' => 'attack'])->assertNotFound();
        $this->deleteJson('/api/v1/master/warehouses/'.$this->warehouseB->id)->assertNotFound();
    }

    public function test_inventory_services_scope_direct_lifecycle_lookups_to_the_authenticated_company(): void
    {
        [$receiptA, $receiptB, $issueA, $issueB] = $this->documents();

        $cases = [
            [app(InventoryReceiptService::class), $receiptA, $receiptB],
            [app(InventoryIssueService::class), $issueA, $issueB],
        ];

        foreach ($cases as [$service, $owned, $foreign]) {
            $this->assertSame($owned->id, $service->getById($owned->id, $this->companyA->id)->id);

            try {
                $service->getById($foreign->id, $this->companyA->id);
                $this->fail('A direct inventory service lookup crossed the tenant boundary.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }

            foreach (['update', 'delete', 'post', 'void', 'unpost', 'duplicate'] as $action) {
                try {
                    $args = $action === 'update'
                        ? [$foreign->id, ['company_id' => $this->companyA->id, 'description' => 'cross-tenant'], $this->companyA->id]
                        : [$foreign->id, $this->companyA->id];
                    $service->{$action}(...$args);
                    $this->fail("A direct inventory {$action} crossed the tenant boundary.");
                } catch (ModelNotFoundException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_inventory_service_lists_reject_a_caller_selected_foreign_company(): void
    {
        try {
            app(InventoryReceiptService::class)->getAll(['company_id' => $this->companyB->id]);
            $this->fail('Inventory receipt service accepted a foreign company filter.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }

        try {
            app(InventoryIssueService::class)->getAll(['company_id' => $this->companyB->id]);
            $this->fail('Inventory issue service accepted a foreign company filter.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }
    }

    public function test_inventory_rejects_foreign_and_unsupported_voucher_reference_targets(): void
    {
        [, $foreignReceipt] = $this->documents();
        $payload = [
            'voucher_number' => 'PNK-CROSS-REFERENCE',
            'warehouse_id' => $this->warehouseA->id,
            'referenced_vouchers' => [[
                'target_type' => InventoryReceipt::class,
                'target_id' => $foreignReceipt->id,
            ]],
            'lines' => [[
                'item_id' => $this->itemA->id,
                'warehouse_id' => $this->warehouseA->id,
                'quantity' => 1,
                'unit_price' => 100,
                'debit_account' => '1561',
                'credit_account' => '331',
            ]],
        ];

        $this->postJson('/api/v1/inventory/receipts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referenced_vouchers.0.target_id');
        $this->postJson('/api/v1/inventory/issues', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referenced_vouchers.0.target_id');

        data_set($payload, 'referenced_vouchers.0.target_type', 'App\\Models\\UnregisteredInventoryVoucher');
        data_set($payload, 'referenced_vouchers.0.target_id', 999);
        $this->postJson('/api/v1/inventory/receipts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referenced_vouchers.0.target_type');
    }

    public function test_stock_report_and_cost_calculation_reject_foreign_filters_and_ignore_company_payload(): void
    {
        [$receiptA, $receiptB] = $this->documents();
        $receiptA->lines()->create(['item_id' => $this->itemA->id, 'warehouse_id' => $this->warehouseA->id, 'quantity' => 2, 'unit_price' => 100, 'amount' => 200]);
        $receiptB->lines()->create(['item_id' => $this->itemB->id, 'warehouse_id' => $this->warehouseB->id, 'quantity' => 99, 'unit_price' => 999, 'amount' => 98901]);
        $receiptA->update(['is_posted' => true]);
        $receiptB->setAttribute('is_posted', true)->saveQuietly();

        $report = $this->getJson('/api/v1/inventory/stock-report?company_id='.$this->companyB->id)->assertOk();
        $this->assertSame([$this->itemA->id], collect($report->json())->pluck('item_id')->all());
        $this->getJson('/api/v1/inventory/stock-report?item_id='.$this->itemB->id)->assertUnprocessable();
        $this->getJson('/api/v1/inventory/stock-report?warehouse_id='.$this->warehouseB->id)->assertUnprocessable();

        $calculation = $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->companyB->id,
            'item_id' => $this->itemA->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
        ])->assertOk();
        $this->assertSame([$this->itemA->id], collect($calculation->json('items'))->pluck('item_id')->all());
        $this->postJson('/api/v1/inventory/cost-calculation/run', ['item_id' => $this->itemB->id])->assertUnprocessable();
        $this->postJson('/api/v1/inventory/cost-calculation/run', ['warehouse_id' => $this->warehouseB->id])->assertUnprocessable();
    }

    public function test_unassigned_user_is_forbidden_from_inventory_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create(['company_id' => null]));

        $this->getJson('/api/v1/inventory/items')->assertForbidden();
        $this->getJson('/api/v1/master/warehouses')->assertForbidden();
        $this->getJson('/api/v1/inventory/receipts')->assertForbidden();
        $this->getJson('/api/v1/inventory/stock-report')->assertForbidden();
        $this->postJson('/api/v1/inventory/cost-calculation/run', [])->assertForbidden();
    }

    /** @return array{InventoryReceipt, InventoryReceipt, InventoryIssue, InventoryIssue} */
    private function documents(): array
    {
        $common = ['voucher_date' => '2026-08-21', 'posting_date' => '2026-08-21', 'status' => 'draft', 'is_posted' => false];
        $receiptA = InventoryReceipt::withoutGlobalScopes()->create([...$common, 'company_id' => $this->companyA->id, 'warehouse_id' => $this->warehouseA->id, 'voucher_number' => 'PNK-'.now()->format('Y').'-0001']);
        $receiptB = InventoryReceipt::withoutGlobalScopes()->create([...$common, 'company_id' => $this->companyB->id, 'warehouse_id' => $this->warehouseB->id, 'voucher_number' => 'PNK-'.now()->format('Y').'-9999']);
        $issueA = InventoryIssue::withoutGlobalScopes()->create([...$common, 'company_id' => $this->companyA->id, 'warehouse_id' => $this->warehouseA->id, 'voucher_number' => 'PXK-'.now()->format('Y').'-0001']);
        $issueB = InventoryIssue::withoutGlobalScopes()->create([...$common, 'company_id' => $this->companyB->id, 'warehouse_id' => $this->warehouseB->id, 'voucher_number' => 'PXK-'.now()->format('Y').'-9999']);

        return [$receiptA, $receiptB, $issueA, $issueB];
    }
}
