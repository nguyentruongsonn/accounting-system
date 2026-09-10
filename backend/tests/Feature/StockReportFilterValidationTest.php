<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StockReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockReportFilterValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'inventory.stock-report.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['company_id' => 1]);
        $user->givePermissionTo('inventory.stock-report.view');
        Sanctum::actingAs($user);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidDateFilters(): array
    {
        return [
            'non-ISO start date' => ['?from_date=2026/01/01', 'from_date'],
            'timestamp start date' => ['?from_date=2026-01-01T10:00:00Z', 'from_date'],
            'non-ISO end date' => ['?to_date=01-31-2026', 'to_date'],
            'impossible calendar date' => ['?from_date=2026-02-29', 'from_date'],
            'date with trailing timestamp' => ['?to_date=2026-01-31%2000:00:00', 'to_date'],
            'reversed range' => ['?from_date=2026-02-01&to_date=2026-01-31', 'to_date'],
        ];
    }

    #[DataProvider('invalidDateFilters')]
    public function test_stock_report_rejects_non_iso_or_reversed_date_filters_before_calculation(string $query, string $field): void
    {
        $service = Mockery::mock(StockReportService::class);
        $service->shouldNotReceive('generateReport');
        $this->app->instance(StockReportService::class, $service);

        $response = $this->getJson('/api/v1/inventory/stock-report'.$query)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The given data was invalid.')
            ->assertJsonValidationErrors($field);

        $this->assertCorrelatedError($response);
    }

    public function test_stock_report_passes_only_the_validated_filter_contract_to_the_legacy_calculation(): void
    {
        $service = Mockery::mock(StockReportService::class);
        $service->shouldReceive('generateReport')
            ->once()
            ->with(1, [
                'from_date' => '2026-01-01',
                'to_date' => '2026-01-31',
            ])
            ->andReturn([]);
        $this->app->instance(StockReportService::class, $service);

        $this->getJson('/api/v1/inventory/stock-report?company_id=999&from_date=2026-01-01&to_date=2026-01-31&unrecognised=must-not-reach-service')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_stock_report_keeps_legacy_one_sided_date_filter_contract(): void
    {
        $service = Mockery::mock(StockReportService::class);
        $service->shouldReceive('generateReport')
            ->once()
            ->with(1, [
                'from_date' => '2026-01-01',
            ])
            ->andReturn([]);
        $this->app->instance(StockReportService::class, $service);

        $this->getJson('/api/v1/inventory/stock-report?from_date=2026-01-01')
            ->assertOk()
            ->assertExactJson([]);
    }

    private function assertCorrelatedError(TestResponse $response): void
    {
        $requestId = $response->json('request_id');
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));
    }
}
