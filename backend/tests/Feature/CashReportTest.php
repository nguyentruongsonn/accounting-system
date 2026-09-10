<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\CashReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Cash report company',
            'tax_code' => '123456789',
            'address' => 'Test address',
        ]);

        Permission::findOrCreate('cash.receipts.view', 'web');
        Permission::findOrCreate('cash.payments.view', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_cash_report_requires_authentication_and_both_cash_permissions(): void
    {
        $this->getJson('/api/v1/cash/reports/S03a1-DNN?date_from=2026-08-01&date_to=2026-08-31')
            ->assertUnauthorized();

        $user = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($user);
        $user->givePermissionTo('cash.receipts.view');

        $this->getJson('/api/v1/cash/reports/S03a1-DNN?date_from=2026-08-01&date_to=2026-08-31')
            ->assertForbidden();

        $paymentOnlyUser = User::factory()->create(['company_id' => $this->company->id]);
        $paymentOnlyUser->givePermissionTo('cash.payments.view');
        Sanctum::actingAs($paymentOnlyUser);

        $this->getJson('/api/v1/cash/reports/S03a1-DNN?date_from=2026-08-01&date_to=2026-08-31')
            ->assertForbidden();
    }

    public function test_cash_report_rejects_unknown_code_and_invalid_period(): void
    {
        $this->actingAsCashReportViewer();

        $this->getJson('/api/v1/cash/reports/UNKNOWN?date_from=2026-08-01&date_to=2026-08-31')
            ->assertUnprocessable();
        $this->getJson('/api/v1/cash/reports/CA-03?date_from=2026-08-31&date_to=2026-08-01')
            ->assertUnprocessable();
        $this->getJson('/api/v1/cash/reports/CA-03?date_to=2026-08-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
        $this->getJson('/api/v1/cash/reports/CA-03?date_from=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
        $this->getJson('/api/v1/cash/reports/CA-03?date_from=2026/08/01&date_to=2026-08-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
        $this->getJson('/api/v1/cash/reports/CA-03?date_from=2026-08-01T00:00:00Z&date_to=2026-08-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    public function test_cash_report_rejects_invalid_status_and_non_cash_account_filter(): void
    {
        $this->actingAsCashReportViewer();

        $this->getJson('/api/v1/cash/reports/CA-03?date_from=2026-08-01&date_to=2026-08-31&status=invalid&cash_account=1121')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'cash_account']);
    }

    public function test_balance_reports_reject_every_non_posted_status(): void
    {
        $this->actingAsCashReportViewer();

        foreach (['CA-01', 'CA-02', 'CA-03'] as $code) {
            foreach (['draft', 'voided', 'all'] as $status) {
                $this->getJson("/api/v1/cash/reports/{$code}?date_from=2026-08-01&date_to=2026-08-31&status={$status}")
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('status');
            }
        }
    }

    public function test_cash_report_uses_authenticated_tenant_and_normalizes_filters_before_delegation(): void
    {
        $service = new class extends CashReportService {
            /** @var list<array{company_id: int, code: string, filters: array}> */
            public array $calls = [];

            public function generate(int $companyId, string $code, array $filters): array
            {
                $this->calls[] = [
                    'company_id' => $companyId,
                    'code' => $code,
                    'filters' => $filters,
                ];

                return ['delegated' => true];
            }
        };
        $this->app->instance(CashReportService::class, $service);
        $this->actingAsCashReportViewer();

        $this->getJson('/api/v1/cash/reports/S03a1-DNN?company_id=999&date_from=2026-08-01&date_to=2026-08-31&search=%20%20PT-01%20%20')
            ->assertOk()
            ->assertJsonPath('data.delegated', true);

        $this->assertSame([[
            'company_id' => $this->company->id,
            'code' => 'S03a1-DNN',
            'filters' => [
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
                'search' => 'PT-01',
                'status' => 'posted',
                'cash_account' => null,
            ],
        ]], $service->calls);
    }

    private function actingAsCashReportViewer(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->givePermissionTo(['cash.receipts.view', 'cash.payments.view']);
        Sanctum::actingAs($user);
    }
}
