<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DepreciationLog;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Period;
use App\Services\FixedAssetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Exercises the service boundary rather than only routes so queue/CLI callers
 * cannot bypass the source-document closed-period control.
 */
class FixedAssetClosedPeriodSourceMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_period_blocks_fixed_asset_source_lifecycle_and_new_events(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');

        try {
            $company = $this->company('Closed fixed-assets', 'FA-CLOSED');
            $this->period($company, true);
            $service = app(FixedAssetService::class);
            $asset = $this->asset($company, 'FA-CLOSED-01');
            $log = DepreciationLog::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'voucher_number' => 'KHTS-2026-08',
                'voucher_date' => '2026-08-31',
                'accounting_date' => '2026-08-31',
                'month' => '2026-08',
                'total_amount' => 0,
                'status' => 'draft',
                'is_posted' => false,
            ]);

            $this->assertClosed(fn () => $service->createAsset($this->assetPayload($company, 'FA-CREATE-CLOSED')));
            $this->assertClosed(fn () => $service->updateAsset($company->id, $asset->id, ['asset_name' => 'must not persist']));
            $this->assertClosed(fn () => $service->postAsset($company->id, $asset->id));
            $this->assertClosed(fn () => $service->unpostAsset($company->id, $asset->id));
            $this->assertClosed(fn () => $service->deleteAsset($company->id, $asset->id));
            $this->assertClosed(fn () => $service->duplicateAsset($company->id, $asset->id));
            $this->assertClosed(fn () => $service->runMonthlyDepreciation($company->id, '2026-08'));
            $this->assertClosed(fn () => $service->unpostMonthlyDepreciation($company->id, $log->id));
            $this->assertClosed(fn () => $service->deleteMonthlyDepreciation($company->id, $log->id));
            $this->assertClosed(fn () => $service->disposeAsset($company->id, $asset->id, ['voucher_date' => '2026-08-15']));
            $this->assertClosed(fn () => $service->revalueAsset($company->id, $asset->id, ['voucher_date' => '2026-08-15', 'new_original_cost' => 2000]));

            $this->assertSame('Closed fixed-asset source', $asset->fresh()->asset_name);
            $this->assertDatabaseHas('fixed_assets', ['id' => $asset->id]);
            $this->assertDatabaseHas('asset_depreciation_logs', ['id' => $log->id]);
            $this->assertDatabaseMissing('asset_disposals', ['fixed_asset_id' => $asset->id]);
            $this->assertDatabaseMissing('asset_revaluations', ['fixed_asset_id' => $asset->id]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_foreign_tenant_closed_period_does_not_block_open_tenant_source_mutation(): void
    {
        $openCompany = $this->company('Open fixed-assets', 'FA-OPEN');
        $closedCompany = $this->company('Foreign closed fixed-assets', 'FA-FOREIGN-CLOSED');
        $this->period($closedCompany, true);
        $asset = $this->asset($openCompany, 'FA-OPEN-01');

        $updated = app(FixedAssetService::class)->updateAsset($openCompany->id, $asset->id, [
            'asset_name' => 'Open tenant mutation succeeds',
        ]);

        $this->assertSame($openCompany->id, $updated->company_id);
        $this->assertSame('Open tenant mutation succeeds', $updated->asset_name);
    }

    private function company(string $name, string $taxCode): Company
    {
        return Company::create(['name' => $name, 'tax_code' => $taxCode]);
    }

    private function period(Company $company, bool $closed): Period
    {
        $fiscal = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        return Period::create([
            'fiscal_year_id' => $fiscal->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => $closed ? 'closed' : 'open',
            'is_closed' => $closed,
        ]);
    }

    private function asset(Company $company, string $number): FixedAsset
    {
        return FixedAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'asset_code' => $number,
            'asset_name' => 'Closed fixed-asset source',
            'purchase_date' => '2026-08-15',
            'start_depreciation_date' => '2026-08-15',
            'original_cost' => 1000,
            'depreciable_cost' => 1000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 83.33,
            'net_value' => 1000,
            'is_active' => true,
            'is_posted' => false,
            'status' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    private function assetPayload(Company $company, string $number): array
    {
        return [
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'asset_code' => $number,
            'asset_name' => 'Blocked new source',
            'purchase_date' => '2026-08-15',
            'original_cost' => 1000,
            'depreciable_cost' => 1000,
            'useful_life_months' => 12,
        ];
    }

    private function assertClosed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a closed-period conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
        }
    }
}
