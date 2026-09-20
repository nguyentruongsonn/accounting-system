<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Period;
use App\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_posting_mode_allows_cost_calculation_without_mapping_guard(): void
    {
        config([
            'accounting.direct_posting_mode' => true,
            'accounting.enforce_inventory_posting_account_mappings' => true,
        ]);

        $company = Company::create(['name' => 'Inventory valuation company']);
        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => 1,
            'period_number' => 1,
            'name' => 'Tháng 01/2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        Item::create([
            'company_id' => $company->id,
            'type' => 'Goods',
            'code' => 'ITEM-VALUATION-'.$company->id,
            'name' => 'Item valuation test',
            'unit' => 'Cái',
            'cost_price' => 100,
            'selling_price' => 150,
            'is_active' => true,
        ]);

        $result = app(InventoryValuationService::class)->runCostCalculation([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
        ]);

        self::assertTrue($result['success']);
        self::assertSame('completed', $result['valuation_run']['status']);
    }
}
