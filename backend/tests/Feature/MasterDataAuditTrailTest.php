<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MasterDataAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Audit Co', 'tax_code' => 'AUDIT-CO']);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $this->company);
        Sanctum::actingAs($user);
    }

    public function test_customer_changes_write_tenant_actor_before_after_and_correlation_evidence(): void
    {
        $headers = ['X-Request-ID' => 'master-audit-correlation-001'];
        $created = $this->withHeaders($headers)->postJson('/api/v1/master/customers', [
            'code' => 'AUD-CUS-001',
            'name' => 'Original customer',
        ])->assertCreated()->json();

        $customerId = $created['id'];
        $this->withHeaders($headers)->putJson('/api/v1/master/customers/'.$customerId, [
            'code' => 'AUD-CUS-001',
            'name' => 'Updated customer',
        ])->assertOk();
        $this->withHeaders($headers)->deleteJson('/api/v1/master/customers/'.$customerId)->assertNoContent();

        $logs = AuditLog::withoutGlobalScopes()
            ->where('model_type', Customer::class)
            ->where('model_id', $customerId)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $logs);
        $this->assertSame(['customer.created', 'customer.updated', 'customer.deleted'], $logs->pluck('action')->all());
        $this->assertTrue($logs->every(fn (AuditLog $log) => $log->company_id === $this->company->id));
        $this->assertTrue($logs->every(fn (AuditLog $log) => $log->user_id === auth()->id()));
        $this->assertTrue($logs->every(fn (AuditLog $log) => $log->correlation_id === $headers['X-Request-ID']));
        $this->assertSame('Original customer', $logs[1]->old_values['name']);
        $this->assertSame('Updated customer', $logs[1]->new_values['name']);
        $this->assertSame('Updated customer', $logs[2]->old_values['name']);
        $this->assertSame([], $logs[2]->new_values);
        $this->assertSame('master_data', $logs[1]->metadata['domain']);
    }

    public function test_audit_failure_rolls_back_master_data_write(): void
    {
        $this->mock(AuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        });

        $this->postJson('/api/v1/master/customers', [
            'code' => 'AUD-ROLLBACK-001',
            'name' => 'Must not persist',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('customers', [
            'company_id' => $this->company->id,
            'code' => 'AUD-ROLLBACK-001',
        ]);
    }

}
