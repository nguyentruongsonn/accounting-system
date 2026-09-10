<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DepreciationLog;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\FixedAssetService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FixedAssetAuthorizationAndTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User $authorizedA;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'FA Tenant A', 'tax_code' => '0100000001', 'address' => 'A']);
        $this->companyB = Company::create(['name' => 'FA Tenant B', 'tax_code' => '0100000002', 'address' => 'B']);
        $this->authorizedA = User::factory()->create(['company_id' => $this->companyA->id]);

        $this->grantFixedAssetPermissions($this->authorizedA);
    }

    public function test_every_fixed_asset_route_requires_an_explicit_permission(): void
    {
        $user = User::factory()->create(['company_id' => $this->companyA->id]);
        Sanctum::actingAs($user);

        $routes = [
            ['get', '/api/v1/fixed-assets'],
            ['post', '/api/v1/fixed-assets', []],
            ['get', '/api/v1/fixed-assets/next-code'],
            ['get', '/api/v1/fixed-assets/depreciation/periods'],
            ['get', '/api/v1/fixed-assets/depreciation/preview?month=2026-08'],
            ['post', '/api/v1/fixed-assets/depreciation/run', ['month' => '2026-08']],
            ['get', '/api/v1/fixed-assets/disposals'],
            ['get', '/api/v1/fixed-assets/revaluations'],
        ];

        foreach ($routes as $route) {
            [$method, $uri] = $route;
            $payload = $route[2] ?? [];
            $response = $method === 'get'
                ? $this->getJson($uri)
                : $this->postJson($uri, $payload);
            $response->assertForbidden();
        }
    }

    public function test_all_fixed_asset_lifecycle_routes_declare_a_fixed_asset_permission(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => Str::startsWith($route->uri(), 'api/v1/fixed-assets'));

        $this->assertCount(20, $routes, 'The complete fixed-asset API surface must remain protected.');

        foreach ($routes as $route) {
            $permissions = collect($route->gatherMiddleware())
                ->filter(fn ($middleware) => Str::startsWith($middleware, 'permission:fixed-assets.'));

            $this->assertNotEmpty(
                $permissions,
                sprintf('Route [%s %s] has no explicit fixed-assets permission.', implode('|', $route->methods()), $route->uri())
            );
        }
    }

    public function test_route_lifecycle_ids_are_explicitly_tenant_scoped_and_cannot_mutate_another_company(): void
    {
        $assetB = $this->assetFor($this->companyB, 'B-ONLY-ASSET');
        $logB = DepreciationLog::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'month' => '2026-08',
            'voucher_number' => 'B-KHTS-01',
            'voucher_date' => '2026-08-31',
            'accounting_date' => '2026-08-31',
            'description' => 'B-only depreciation',
            'total_amount' => 0,
            'is_posted' => false,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->authorizedA);

        $this->getJson("/api/v1/fixed-assets/{$assetB->id}")->assertNotFound();
        $this->putJson("/api/v1/fixed-assets/{$assetB->id}", ['asset_name' => 'cross-tenant overwrite'])->assertNotFound();
        $this->postJson("/api/v1/fixed-assets/{$assetB->id}/post")->assertNotFound();
        $this->postJson("/api/v1/fixed-assets/{$assetB->id}/unpost")->assertNotFound();
        $this->postJson("/api/v1/fixed-assets/{$assetB->id}/duplicate")->assertNotFound();
        $this->postJson("/api/v1/fixed-assets/{$assetB->id}/dispose", ['voucher_date' => '2026-08-31'])->assertNotFound();
        $this->postJson("/api/v1/fixed-assets/{$assetB->id}/revalue", ['new_original_cost' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/fixed-assets/{$assetB->id}")->assertNotFound();
        $this->getJson("/api/v1/fixed-assets/depreciation/periods/{$logB->id}")->assertNotFound();
        $this->postJson("/api/v1/fixed-assets/depreciation/{$logB->id}/unpost")->assertNotFound();
        $this->deleteJson("/api/v1/fixed-assets/depreciation/{$logB->id}")->assertNotFound();

        $this->assertSame('B-only asset', FixedAsset::withoutGlobalScopes()->findOrFail($assetB->id)->asset_name);
        $this->assertDatabaseMissing('asset_disposals', ['fixed_asset_id' => $assetB->id]);
        $this->assertDatabaseMissing('asset_revaluations', ['fixed_asset_id' => $assetB->id]);
    }

    public function test_fixed_asset_lookup_errors_do_not_expose_exception_details(): void
    {
        Sanctum::actingAs($this->authorizedA);

        $this->getJson('/api/v1/fixed-assets/999999')
            ->assertNotFound()
            ->assertJsonPath('error', 'Resource not found.')
            ->assertJsonMissing(['error' => 'No query results for model [App\\Models\\FixedAsset].']);
    }

    public function test_unexpected_fixed_asset_errors_do_not_expose_internal_details(): void
    {
        $internalDetail = 'SQLSTATE[42S02] C:\\private\\FixedAssetService.php secret=fixed-asset-secret';
        $service = Mockery::mock(FixedAssetService::class);
        $service->shouldReceive('getById')->once()->with($this->companyA->id, 1)
            ->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(FixedAssetService::class, $service);
        Sanctum::actingAs($this->authorizedA);

        $response = $this->getJson('/api/v1/fixed-assets/1')
            ->assertStatus(500)
            ->assertJsonPath('error', 'Unable to load the fixed asset.');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('fixed-asset-secret', $response->getContent());
        $this->assertStringNotContainsString('FixedAssetService.php', $response->getContent());
    }

    public function test_service_requires_explicit_tenant_for_raw_lifecycle_ids(): void
    {
        $assetB = $this->assetFor($this->companyB, 'B-SERVICE-ONLY');
        Sanctum::actingAs($this->authorizedA);

        $this->expectException(ModelNotFoundException::class);
        app(FixedAssetService::class)->postAsset($this->companyA->id, $assetB->id);
    }

    public function test_direct_service_cannot_list_or_mutate_a_foreign_fixed_asset_tenant(): void
    {
        $assetA = $this->assetFor($this->companyA, 'A-SERVICE-LIST');
        $assetB = $this->assetFor($this->companyB, 'B-SERVICE-LIST');
        Sanctum::actingAs($this->authorizedA);
        $service = app(FixedAssetService::class);

        $this->assertSame([$assetA->id], $service->getAll()->pluck('id')->all());

        foreach ([
            fn () => $service->getById($this->companyB->id, $assetB->id),
            fn () => $service->postAsset($this->companyB->id, $assetB->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An authenticated fixed-asset service caller must not select a foreign company.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }
        }

        $this->assertFalse((bool) $assetB->fresh()->is_posted);
    }

    private function grantFixedAssetPermissions(User $user): void
    {
        $permissions = [
            'fixed-assets.view', 'fixed-assets.create', 'fixed-assets.update', 'fixed-assets.delete',
            'fixed-assets.post', 'fixed-assets.unpost', 'fixed-assets.disposals.view',
            'fixed-assets.disposals.create', 'fixed-assets.revaluations.view', 'fixed-assets.revaluations.create',
            'fixed-assets.depreciation.view', 'fixed-assets.depreciation.run',
            'fixed-assets.depreciation.unpost', 'fixed-assets.depreciation.delete',
        ];
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);
    }

    private function assetFor(Company $company, string $code): FixedAsset
    {
        return FixedAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'asset_code' => $code,
            'asset_name' => 'B-only asset',
            'voucher_number' => $code,
            'voucher_date' => '2026-08-01',
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
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
}
