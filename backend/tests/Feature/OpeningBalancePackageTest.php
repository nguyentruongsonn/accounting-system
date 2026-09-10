<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OpeningBalancePackageTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private Customer $customer;
    private Supplier $supplier;
    private Item $item;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        TwoRolePermissions::seed();
        $this->accountant = User::factory()->create(['company_id' => 1]);
        $this->accountant->assignRole('accountant');
        Sanctum::actingAs($this->accountant);
        foreach (['131', '1561', '331'] as $code) {
            ChartOfAccount::create([
                'company_id' => 1, 'code' => $code, 'name' => 'Opening '.$code,
                'type' => 'asset', 'nature' => 'debit', 'level' => 1,
                'is_parent' => false, 'is_active' => true,
            ]);
        }
        $this->customer = Customer::create(['company_id' => 1, 'code' => 'CUS-OPEN', 'name' => 'Opening customer']);
        $this->supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-OPEN', 'name' => 'Opening supplier']);
        $this->item = Item::create(['company_id' => 1, 'code' => 'ITEM-OPEN', 'name' => 'Opening item', 'type' => 'inventory']);
        $this->warehouse = Warehouse::create(['company_id' => 1, 'code' => 'WH-OPEN', 'name' => 'Opening warehouse']);
    }

    public function test_accountant_can_prepare_and_confirm_a_reconciled_opening_package(): void
    {
        $created = $this->postJson('/api/v1/opening-balances', $this->balancedPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $id = $created->json('data.id');
        $this->postJson("/api/v1/opening-balances/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.reconciliation.balanced', true);

        $this->assertDatabaseHas('opening_balance_packages', [
            'id' => $id, 'company_id' => 1, 'status' => 'confirmed',
        ]);
        $trial = app(\App\Services\FinancialReportService::class)
            ->getTrialBalance(1, '2026-01-01', '2026-01-31')->keyBy('code');
        $this->assertSame('100.00', $trial['131']['opening_debit']);
        $this->assertSame('50.00', $trial['1561']['opening_debit']);
        $this->assertSame('150.00', $trial['331']['opening_credit']);
    }

    public function test_unreconciled_package_cannot_be_confirmed_and_remains_draft(): void
    {
        $payload = $this->balancedPayload();
        $payload['account_lines'][0]['debit_amount'] = '99.00';
        $id = $this->postJson('/api/v1/opening-balances', $payload)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/opening-balances/{$id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reconciliation');
        $this->assertDatabaseHas('opening_balance_packages', ['id' => $id, 'status' => 'draft']);
    }

    /** @return array<string,mixed> */
    private function balancedPayload(): array
    {
        return [
            'effective_date' => '2026-01-01',
            'account_lines' => [
                ['account_code' => '131', 'debit_amount' => '100.00', 'credit_amount' => '0.00'],
                ['account_code' => '1561', 'debit_amount' => '50.00', 'credit_amount' => '0.00'],
                ['account_code' => '331', 'debit_amount' => '0.00', 'credit_amount' => '150.00'],
            ],
            'party_lines' => [
                ['party_type' => 'customer', 'party_id' => $this->customer->id, 'account_code' => '131', 'debit_amount' => '100.00', 'credit_amount' => '0.00'],
                ['party_type' => 'supplier', 'party_id' => $this->supplier->id, 'account_code' => '331', 'debit_amount' => '0.00', 'credit_amount' => '150.00'],
            ],
            'inventory_lines' => [[
                'item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id,
                'account_code' => '1561', 'quantity' => '5.0000',
                'unit_cost' => '10.0000', 'total_value' => '50.00',
            ]],
        ];
    }
}
