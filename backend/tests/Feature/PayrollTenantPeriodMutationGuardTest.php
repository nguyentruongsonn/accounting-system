<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\Period;
use App\Models\User;
use App\Services\PayrollService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Payroll source controls must hold for HTTP and non-HTTP callers.  In
 * particular, period checks happen only after an exact tenant lookup so a
 * foreign raw ID cannot leak an accounting date or closed-period state.
 */
class PayrollTenantPeriodMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_period_rejects_create_post_and_void_without_partial_source_or_journal_mutation(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');

        try {
            $company = $this->company('Closed payroll tenant', 'PAYROLL-CLOSED');
            $this->period($company, true);
            $service = app(PayrollService::class);
            $existing = $this->payroll($company, 'PR-CLOSED-EXISTING');
            $posted = $this->payroll($company, 'PR-CLOSED-POSTED', true);

            $this->assertClosed(fn () => $service->create($company->id, $this->payload('PR-CLOSED-CREATE')));
            $this->assertClosed(fn () => $service->post($company->id, $existing->id));
            $this->assertClosed(fn () => $service->void($company->id, $posted->id));

            $this->assertDatabaseMissing('payrolls', ['voucher_number' => 'PR-CLOSED-CREATE']);
            $this->assertDatabaseCount('payroll_lines', 2);
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertFalse((bool) $existing->fresh()->is_posted);
            $this->assertTrue((bool) $posted->fresh()->is_posted);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_explicit_open_tenant_service_caller_can_post_and_void_when_another_tenant_is_closed(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');

        try {
            $openCompany = $this->company('Open payroll tenant', 'PAYROLL-OPEN');
            $closedCompany = $this->company('Foreign closed payroll tenant', 'PAYROLL-FOREIGN');
            $this->period($openCompany, false);
            $this->period($closedCompany, true);
            $this->accounts($openCompany);

            // No authenticated request is involved: the explicit company ID
            // must make the service safe for worker/CLI lifecycle callers.
            $service = app(PayrollService::class);
            $payroll = $service->create($openCompany->id, $this->payload('PR-OPEN'));
            $posted = $service->post($openCompany->id, $payroll->id);
            $voided = $service->void($openCompany->id, $payroll->id);

            $this->assertSame($openCompany->id, $posted->company_id);
            $this->assertNotNull($posted->journal_entry_id);
            $this->assertFalse((bool) $voided->is_posted);
            $this->assertSame('voided', JournalEntry::withoutGlobalScopes()->findOrFail($posted->journal_entry_id)->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_foreign_raw_id_is_not_found_before_date_or_status_is_observed_in_service_and_api(): void
    {
        $companyA = $this->company('Payroll actor tenant', 'PAYROLL-A');
        $companyB = $this->company('Payroll foreign tenant', 'PAYROLL-B');
        $this->period($companyB, true);
        $foreign = $this->payroll($companyB, 'PR-FOREIGN');

        try {
            app(PayrollService::class)->post($companyA->id, $foreign->id);
            $this->fail('A foreign raw payroll ID must be indistinguishable from a missing one.');
        } catch (ModelNotFoundException) {
            $this->assertFalse((bool) $foreign->fresh()->is_posted);
            $this->assertDatabaseCount('journal_entries', 0);
        }

        $actor = User::factory()->create(['company_id' => $companyA->id]);
        foreach (['payroll.post', 'payroll.unpost'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $actor->givePermissionTo(['payroll.post', 'payroll.unpost']);
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/payroll/{$foreign->id}/post")->assertNotFound();
        $this->postJson("/api/v1/payroll/{$foreign->id}/void")->assertNotFound();

        $this->assertFalse((bool) $foreign->fresh()->is_posted);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_authenticated_service_cannot_select_a_foreign_payroll_company(): void
    {
        $companyA = $this->company('Payroll direct-service actor', 'PAYROLL-DIRECT-A');
        $companyB = $this->company('Payroll direct-service foreign', 'PAYROLL-DIRECT-B');
        $foreign = $this->payroll($companyB, 'PR-DIRECT-FOREIGN');
        $actor = User::factory()->create(['company_id' => $companyA->id]);
        Sanctum::actingAs($actor);
        $service = app(PayrollService::class);

        $operations = [
            fn () => $service->getAll($companyB->id),
            fn () => $service->create($companyB->id, $this->payload('PR-DIRECT-CREATE')),
            fn () => $service->post($companyB->id, $foreign->id),
            fn () => $service->void($companyB->id, $foreign->id),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('An authenticated payroll service caller must not select a foreign company.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }
        }

        $this->assertDatabaseMissing('payrolls', ['voucher_number' => 'PR-DIRECT-CREATE']);
        $this->assertFalse((bool) $foreign->fresh()->is_posted);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_service_does_not_materialize_legacy_payroll_account_defaults(): void
    {
        $company = $this->company('Payroll explicit mapping tenant', 'PAYROLL-EXPLICIT');
        $payload = $this->payload('PR-MISSING-ACCOUNTS');
        unset($payload['lines'][0]['debit_account'], $payload['lines'][0]['credit_account']);

        try {
            app(PayrollService::class)->create($company->id, $payload);
            $this->fail('Payroll creation must reject incomplete account evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.account', $exception->errors());
        }

        $this->assertDatabaseMissing('payrolls', ['voucher_number' => 'PR-MISSING-ACCOUNTS']);
    }

    public function test_post_rejects_legacy_payroll_line_with_missing_persisted_account(): void
    {
        $company = $this->company('Payroll posted evidence tenant', 'PAYROLL-POST-EVIDENCE');
        $this->period($company, false);
        $payroll = $this->payroll($company, 'PR-MISSING-PERSISTED-ACCOUNT');
        $payroll->lines()->firstOrFail()->update(['debit_account' => '']);

        try {
            app(PayrollService::class)->post($company->id, $payroll->id);
            $this->fail('Payroll posting must reject missing persisted account evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.account', $exception->errors());
        }

        $this->assertFalse((bool) $payroll->fresh()->is_posted);
        $this->assertDatabaseCount('journal_entries', 0);
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

    private function payroll(Company $company, string $number, bool $isPosted = false): Payroll
    {
        $payroll = Payroll::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'month' => '2026-08',
            'total_amount' => 100,
            'is_posted' => $isPosted,
        ]);
        $payroll->lines()->create([
            'employee_name' => 'Payroll guard worker',
            'net_salary' => 100,
            'debit_account' => '6421',
            'credit_account' => '3341',
        ]);

        return $payroll;
    }

    private function accounts(Company $company): void
    {
        foreach ([['6421', 'Salary expense', 'expense'], ['3341', 'Payroll payable', 'liability']] as [$code, $name, $type]) {
            ChartOfAccount::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $number): array
    {
        return [
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'month' => '2026-08',
            'description' => 'Payroll period guard',
            'lines' => [[
                'employee_name' => 'Payroll guard worker',
                'net_salary' => 100,
                'debit_account' => '6421',
                'credit_account' => '3341',
            ]],
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
