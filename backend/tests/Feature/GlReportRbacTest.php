<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GlReportRbacTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'gl.journal-entries.view',
        'gl.journal-entries.create',
        'gl.journal-entries.update',
        'gl.journal-entries.delete',
        'gl.journal-entries.post',
        'gl.journal-entries.reverse',
        'gl.periods.view',
        'gl.periods.close',
        'reports.view',
    ];

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_gl_period_and_report_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/gl/journal-entries')->assertUnauthorized();
        $this->getJson('/api/v1/gl/periods')->assertUnauthorized();
        $this->getJson('/api/v1/reports/general-journal')->assertUnauthorized();
    }

    public function test_authenticated_user_without_permissions_is_forbidden(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/gl/journal-entries')->assertForbidden();
        $this->getJson('/api/v1/gl/periods')->assertForbidden();
        $this->getJson('/api/v1/reports/general-journal')->assertForbidden();
    }

    public function test_each_read_permission_grants_only_its_intended_read_surface(): void
    {
        $journalUser = $this->actingAsUser(['gl.journal-entries.view']);
        $this->getJson('/api/v1/gl/journal-entries')->assertOk();
        $this->postJson('/api/v1/gl/journal-entries', [])->assertForbidden();

        $periodUser = $this->actingAsUser(['gl.periods.view']);
        $this->getJson('/api/v1/gl/periods')->assertOk();
        $this->postJson('/api/v1/gl/periods/close', ['period_id' => 1])->assertForbidden();

        $reportUser = $this->actingAsUser(['reports.view']);
        $this->getJson('/api/v1/reports/general-journal')->assertOk();
        $this->getJson('/api/v1/reports/general-ledger?account_code=1111')->assertOk();
        $this->getJson('/api/v1/gl/periods')->assertForbidden();

        $this->assertNotSame($journalUser->id, $periodUser->id);
        $this->assertNotSame($periodUser->id, $reportUser->id);
    }

    public function test_journal_action_permissions_do_not_authorize_other_actions(): void
    {
        $this->actingAsUser(['gl.journal-entries.post']);

        $this->getJson('/api/v1/gl/journal-entries')->assertForbidden();
        $this->postJson('/api/v1/gl/journal-entries/999/reverse', [
            'reason' => 'Correction required',
        ])->assertForbidden();
    }

    public function test_authorised_gl_report_requests_are_tenant_scoped_and_append_minimal_forensic_audit(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other report tenant',
            'tax_code' => 'OTHER-REPORT-001',
            'address' => 'Test address',
        ]);
        $user = $this->actingAsUser(['reports.view']);
        $requestId = 'aa6adbf9-c6e4-4fa4-a3dc-9e291718b998';

        $this->withHeader('X-Request-ID', $requestId)
            ->getJson('/api/v1/reports/general-journal?company_id='.$otherCompany->id.'&from_date=2026-01-01&to_date=2026-01-31')
            ->assertOk();
        $this->getJson('/api/v1/reports/general-ledger?account_code=1111&from_date=2026-01-01&to_date=2026-01-31')
            ->assertOk()
            ->assertJsonPath('meta.opening_balance.as_of_date', '2025-12-31')
            ->assertJsonPath('meta.opening_balance.debit', '0.00')
            ->assertJsonPath('meta.opening_balance.credit', '0.00');
        $this->getJson('/api/v1/reports/trial-balance?from_date=2026-01-01&to_date=2026-01-31')->assertOk();
        $this->getJson('/api/v1/reports/balance-sheet?from_date=2026-01-01&to_date=2026-01-31')->assertOk();
        $this->getJson('/api/v1/reports/income-statement?from_date=2026-01-01&to_date=2026-01-31')->assertOk();

        $audits = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->where('action', 'like', 'report.%')
            ->orderBy('id')
            ->get();

        $this->assertCount(5, $audits);
        $this->assertSame([
            'report.general_journal.view',
            'report.general_ledger.view',
            'report.trial_balance.view',
            'report.balance_sheet.view',
            'report.income_statement.view',
        ], $audits->pluck('action')->all());
        $this->assertSame($requestId, $audits->first()->correlation_id);
        $this->assertSame($user->id, $audits->first()->user_id);
        $this->assertSame('report-issuance.v1', $audits->first()->metadata['audit_schema']);
        $this->assertSame(1, $audits->first()->metadata['regime']['fiscal_year_id']);
        $this->assertArrayNotHasKey('data', $audits->first()->metadata);
        $this->assertArrayNotHasKey('rows', $audits->first()->metadata);
        $this->assertDatabaseMissing('audit_logs', [
            'company_id' => $otherCompany->id,
            'action' => 'report.general_journal.view',
        ]);
    }

    public function test_successful_export_is_audited_with_delivery_mode(): void
    {
        $user = $this->actingAsUser(['reports.view']);

        $this->get('/api/v1/reports/trial-balance?export=excel&from_date=2026-01-01&to_date=2026-01-31')
            ->assertOk();

        $audit = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->where('action', 'report.trial_balance.export_excel')
            ->firstOrFail();

        $this->assertSame('export_excel', $audit->metadata['delivery']);
        $this->assertSame('2026-01-31', $audit->metadata['period']['to_date']);
    }

    public function test_passive_report_audit_failure_does_not_fail_an_authorised_read(): void
    {
        $this->actingAsUser(['reports.view']);
        $this->app->instance(AuditService::class, new class extends AuditService
        {
            public function record(
                Model $model,
                string $action,
                array $before = [],
                array $after = [],
                ?string $correlationId = null,
                array $metadata = []
            ): AuditLog {
                throw new \RuntimeException('Injected passive audit failure');
            }
        });

        $this->getJson('/api/v1/reports/general-journal?from_date=2026-01-01&to_date=2026-01-31')
            ->assertOk();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsUser(array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => 1]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        Sanctum::actingAs($user);

        return $user;
    }
}
