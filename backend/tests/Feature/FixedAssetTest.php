<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Company',
            'tax_code' => '123456789',
            'address' => 'Test Address',
        ]);

        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->user);

        FiscalYear::create([
            'company_id' => $this->company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '211', 'name' => 'Fixed Assets', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '331', 'name' => 'Accounts Payable', 'type' => 'liability', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '214', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '642', 'name' => 'Management Expense', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
    }

    public function test_can_create_fixed_asset()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'FA-001',
            'voucher_date' => '2026-08-14',
            'asset_code' => 'ASSET-01',
            'asset_name' => 'Test Asset',
            'purchase_date' => '2026-08-14',
            'start_depreciation_date' => '2026-08-14',
            'original_cost' => 12000000,
            'depreciable_cost' => 12000000,
            'useful_life_months' => 12,
            'asset_account' => '211',
            'depreciation_account' => '214',
            'expense_account' => '642',
            'credit_account' => '331',
            'is_posted' => true,
        ];

        $response = $this->postJson('/api/v1/fixed-assets', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('asset_code', 'ASSET-01');

        $this->assertDatabaseHas('fixed_assets', [
            'asset_code' => 'ASSET-01',
        ]);

        // It creates a journal entry
        $asset = FixedAsset::where('asset_code', 'ASSET-01')->first();
        $this->assertDatabaseHas('journal_entries', [
            'id' => $asset->journal_entry_id,
            'status' => 'posted',
        ]);
    }

    public function test_can_run_depreciation()
    {
        // First create an asset
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'FA-002',
            'voucher_date' => '2026-07-14',
            'asset_code' => 'ASSET-02',
            'asset_name' => 'Test Asset 2',
            'purchase_date' => '2026-07-14',
            'start_depreciation_date' => '2026-07-01',
            'original_cost' => 12000000,
            'depreciable_cost' => 12000000,
            'useful_life_months' => 12,
            'asset_account' => '211',
            'depreciation_account' => '214',
            'expense_account' => '642',
            'credit_account' => '331',
            'is_posted' => true,
        ];

        $this->postJson('/api/v1/fixed-assets', $payload);

        $depreciationPayload = [
            'company_id' => $this->company->id,
            'month' => '2026-07',
        ];

        $response = $this->postJson('/api/v1/fixed-assets/depreciate', $depreciationPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('asset_depreciation_logs', [
            'company_id' => $this->company->id,
            'month' => '2026-07',
        ]);
    }

    public function test_can_get_all_fixed_assets()
    {
        FixedAsset::create([
            'company_id' => $this->company->id,
            'asset_code' => 'ASSET-ALL-01',
            'asset_name' => 'Asset For All List',
            'purchase_date' => '2026-08-01',
            'original_cost' => 5000000,
            'depreciable_cost' => 5000000,
            'useful_life_months' => 24,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/fixed-assets');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data);
        $this->assertTrue(collect($data)->contains('asset_code', 'ASSET-ALL-01'));
    }
}
