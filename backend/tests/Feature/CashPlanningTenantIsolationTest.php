<?php

namespace Tests\Feature;

use App\Models\CashForecast;
use App\Models\CashInventory;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashPlanningTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::findOrFail(1);
        $this->companyB = Company::create([
            'name' => 'Company B',
            'tax_code' => 'TENANT-B-CASH-PLANNING',
            'address' => 'B',
        ]);
        ChartOfAccount::create([
            'company_id' => $this->companyA->id,
            'code' => '1111',
            'name' => 'Cash test account',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ]);
    }

    public function test_forecast_lists_show_and_create_are_tenant_scoped(): void
    {
        $own = $this->forecast($this->companyA->id, 'Own forecast');
        $foreign = $this->forecast($this->companyB->id, 'Foreign forecast');
        $this->actingAsCompany($this->companyA);

        $this->getJson('/api/v1/cash-forecasts?company_id='.$this->companyB->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $own->id);
        $this->getJson('/api/v1/cash-forecasts/'.$foreign->id)->assertNotFound();

        $response = $this->postJson('/api/v1/cash-forecasts', [
            'company_id' => $this->companyB->id,
            'period_name' => 'Malicious switch',
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'items' => [],
        ])->assertCreated();

        $this->assertDatabaseHas('cash_forecasts', [
            'id' => $response->json('id'),
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_cash_inventory_crud_is_tenant_scoped_and_numbers_are_unique_per_company(): void
    {
        $own = $this->inventory($this->companyA->id, 'KK-001');
        $foreign = $this->inventory($this->companyB->id, 'KK-SHARED');
        $this->actingAsCompany($this->companyA);

        $this->getJson('/api/v1/cash-inventories?company_id='.$this->companyB->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $own->id);
        $this->getJson('/api/v1/cash-inventories/'.$foreign->id)->assertNotFound();
        $this->deleteJson('/api/v1/cash-inventories/'.$foreign->id)->assertNotFound();
        $this->assertDatabaseHas('cash_inventories', ['id' => $foreign->id]);

        $response = $this->postJson('/api/v1/cash-inventories', [
            'company_id' => $this->companyB->id,
            'audit_number' => 'KK-SHARED',
            'audit_date' => '2026-08-21',
            'account_code' => '1111',
            'book_balance' => 100,
            'actual_balance' => 100,
            'difference' => 0,
            'status' => 'Draft',
            'lines' => [],
        ])->assertCreated();

        $this->assertDatabaseHas('cash_inventories', [
            'id' => $response->json('id'),
            'company_id' => $this->companyA->id,
            'audit_number' => 'KK-SHARED',
        ]);
    }

    public function test_unassigned_user_is_forbidden_from_cash_planning_data(): void
    {
        $user = User::factory()->create(['company_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/cash-forecasts')->assertForbidden();
        $this->postJson('/api/v1/cash-forecasts', [])->assertForbidden();
        $this->getJson('/api/v1/cash-inventories')->assertForbidden();
        $this->postJson('/api/v1/cash-inventories', [])->assertForbidden();
    }

    public function test_cash_inventory_next_code_is_company_scoped(): void
    {
        $this->inventory($this->companyA->id, 'KK00007');
        $this->inventory($this->companyB->id, 'KK00099');
        $this->actingAsCompany($this->companyA);

        $this->getJson('/api/v1/cash-inventories/next-code')
            ->assertOk()
            ->assertJsonPath('data.code', 'KK00008');
    }

    private function actingAsCompany(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function forecast(int $companyId, string $periodName): CashForecast
    {
        return CashForecast::withoutGlobalScope('company')->create([
            'company_id' => $companyId,
            'period_name' => $periodName,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'created_date' => '2026-08-21',
        ]);
    }

    private function inventory(int $companyId, string $number): CashInventory
    {
        return CashInventory::withoutGlobalScope('company')->create([
            'company_id' => $companyId,
            'audit_number' => $number,
            'audit_date' => '2026-08-21',
            'book_balance' => 0,
            'actual_balance' => 0,
            'difference' => 0,
            'status' => 'Draft',
            'account_code' => '1111',
        ]);
    }
}
