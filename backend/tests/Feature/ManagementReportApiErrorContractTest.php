<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManagementReportApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    /** @return array<string, array{string}> */
    public static function capabilityRoutes(): array
    {
        return [
            'manifest' => ['/api/v1/reports/management-capabilities'],
            'ap-aging' => ['/api/v1/purchase/ap-aging'],
            'ar-aging' => ['/api/v1/sales/ar-aging'],
            'stock' => ['/api/v1/inventory/stock-report'],
            'budget' => ['/api/v1/budgets/report?year=2026'],
            'v2-ap-aging' => ['/api/v2/management-reports/ap-aging?as_of_date=2026-08-22'],
            'v2-ar-aging' => ['/api/v2/management-reports/ar-aging?as_of_date=2026-08-22'],
            'v2-stock-movement' => ['/api/v2/management-reports/stock-movement?from_date=2026-08-01&to_date=2026-08-31'],
            'v2-budget-vs-actual' => ['/api/v2/management-reports/budget-vs-actual?fiscal_year_id=1&scenario_id=1'],
        ];
    }

    #[DataProvider('capabilityRoutes')]
    public function test_unauthenticated_capability_routes_use_correlated_non_leaking_error_contract(string $path): void
    {
        $response = $this->getJson($path)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Unauthenticated.');

        $this->assertCorrelatedError($response);
        $this->assertStringNotContainsString('vendor', $response->getContent());
    }

    #[DataProvider('capabilityRoutes')]
    public function test_unauthorized_capability_routes_use_correlated_non_leaking_error_contract(string $path): void
    {
        foreach (['reports.view', 'purchase.reports.view', 'sales.reports.view', 'inventory.stock-report.view', 'budgets.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs(User::factory()->create(['company_id' => 1]));

        $response = $this->getJson($path)
            ->assertForbidden()
            ->assertJsonPath('error', 'This action is unauthorized.');

        $this->assertCorrelatedError($response);
        $this->assertStringNotContainsString('Spatie', $response->getContent());
    }

    private function assertCorrelatedError(TestResponse $response): void
    {
        $requestId = $response->json('request_id');
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));
    }
}
