<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class FinancialAggregateAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aggregate Audit Co', 'tax_code' => 'AGG-AUDIT']);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $this->company);
        Sanctum::actingAs($user);
    }

    public function test_cash_forecast_creation_has_tenant_actor_and_lifecycle_audit_evidence(): void
    {
        $response = $this->withHeader('X-Request-ID', 'forecast-audit-001')->postJson('/api/v1/cash-forecasts', [
            'period_name' => 'Tháng 08/2026',
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'items' => [['code' => 'IN', 'name' => 'Thu dự kiến', 'amount' => 100]],
        ])->assertCreated();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'cash_forecast.created')->firstOrFail();
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame(auth()->id(), $log->user_id);
        $this->assertSame('forecast-audit-001', $log->correlation_id);
        $this->assertSame($response->json('id'), $log->model_id);
        $this->assertSame(1, $log->metadata['item_count']);
        $this->assertSame('ACCOUNTANT', $response->json('creator'));
    }

    public function test_budget_write_is_rolled_back_when_its_append_only_audit_write_fails(): void
    {
        $this->mock(AuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        });

        try {
            app(BudgetService::class)->setBudget($this->company->id, '2026', '642', ['jan' => 100]);
            $this->fail('Expected the audit failure to abort the budget write.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseMissing('budgets', [
            'company_id' => $this->company->id,
            'year' => '2026',
            'account_code' => '642',
        ]);
    }
}
