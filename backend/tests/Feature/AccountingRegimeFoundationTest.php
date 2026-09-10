<?php

namespace Tests\Feature;

use App\Enums\AccountingRegime;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingRegimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingRegimeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscal_year_boundary_defaults_older_year_to_tt200_and_2026_year_to_tt99(): void
    {
        $company = Company::create(['name' => 'Regime boundary', 'tax_code' => 'REGIME-BOUNDARY']);
        $older = $this->createFiscalYear($company, 2025, '2025-01-01', '2025-12-31');
        $current = $this->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');

        $this->assertSame(
            AccountingRegime::TT200,
            $older->accountingRegimeProfile()->firstOrFail()->regime
        );
        $this->assertSame(
            AccountingRegime::TT99,
            $current->accountingRegimeProfile()->firstOrFail()->regime
        );
        $profile = $current->accountingRegimeProfile()->firstOrFail();
        $this->assertSame($company->id, $profile->company_id);
        $this->assertSame('TT99', $profile->regime->value);
        $this->assertSame('2026-01-01', $profile->effective_from->toDateString());
        $this->assertSame('2026-12-31', $profile->effective_to->toDateString());
    }

    public function test_invalid_regime_or_effective_dates_are_rejected(): void
    {
        $profile = FiscalYear::where('company_id', 1)->firstOrFail()
            ->accountingRegimeProfile()->firstOrFail();

        try {
            $profile->update(['regime' => 'TT200']);
            $this->fail('Expected TT200 to be rejected for a fiscal year starting in 2026.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('regime', $exception->errors());
        }

        $profile->refresh();
        try {
            $profile->update(['effective_from' => '2026-02-01']);
            $this->fail('Expected a partial-year regime profile to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
        }

        $this->assertSame('TT99', $profile->fresh()->regime->value);
        $this->assertSame('2026-01-01', $profile->fresh()->effective_from->toDateString());
    }

    public function test_regime_resolution_cannot_cross_tenant_boundary(): void
    {
        $companyA = Company::create(['name' => 'Regime tenant A', 'tax_code' => 'REGIME-A']);
        $companyB = Company::create(['name' => 'Regime tenant B', 'tax_code' => 'REGIME-B']);
        $yearB = $this->createFiscalYear($companyB, 2026, '2026-01-01', '2026-12-31');

        try {
            app(AccountingRegimeService::class)->forFiscalYear($companyA->id, $yearB->id);
            $this->fail('Expected foreign fiscal-year profile resolution to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_year_id', $exception->errors());
        }

        $this->assertDatabaseHas('accounting_regime_profiles', [
            'company_id' => $companyB->id,
            'fiscal_year_id' => $yearB->id,
            'regime' => 'TT99',
        ]);
    }

    public function test_authenticated_regime_resolution_cannot_request_a_foreign_company(): void
    {
        $companyA = Company::create(['name' => 'Regime actor tenant', 'tax_code' => 'REGIME-ACTOR']);
        $companyB = Company::create(['name' => 'Regime foreign tenant', 'tax_code' => 'REGIME-FOREIGN']);
        $yearB = $this->createFiscalYear($companyB, 2026, '2026-01-01', '2026-12-31');
        Sanctum::actingAs(User::factory()->create(['company_id' => $companyA->id]));

        $this->expectException(ValidationException::class);

        app(AccountingRegimeService::class)->forFiscalYear($companyB->id, $yearB->id);
    }

    public function test_report_metadata_uses_authenticated_tenant_and_fiscal_year_regime(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        $this->grantGlReportPermissions($user);
        Sanctum::actingAs($user);
        $fiscalYear = FiscalYear::where('company_id', 1)->firstOrFail();

        $response = $this->getJson('/api/v1/reports/balance-sheet?'.http_build_query([
            'company_id' => 999999,
            'from_date' => '2026-01-01',
            'to_date' => '2026-08-31',
            'fiscal_year_id' => $fiscalYear->id,
            'include_metadata' => 1,
        ]))->assertOk();

        $response
            ->assertJsonPath('meta.company_id', 1)
            ->assertJsonPath('meta.fiscal_year_id', $fiscalYear->id)
            ->assertJsonPath('meta.accounting_regime', 'TT99')
            ->assertJsonPath('meta.accounting_regime_label', 'Thông tư 99/2025/TT-BTC')
            ->assertJsonPath('meta.effective_from', '2026-01-01');

        $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'CashReceipt',
            'target_voucher_type' => 'SalesInvoice',
            'target_voucher_id' => 999999,
            'fiscal_year_id' => $fiscalYear->id,
            'standard' => 'TT200',
        ])->assertStatus(422)->assertJsonValidationErrors('standard');
    }

    public function test_report_metadata_can_resolve_historical_tt200_fiscal_year(): void
    {
        $company = Company::create(['name' => 'Historical regime', 'tax_code' => 'REGIME-HIST']);
        $fiscalYear = $this->createFiscalYear($company, 2025, '2025-01-01', '2025-12-31');

        $metadata = app(AccountingRegimeService::class)->reportMetadata(
            $company->id,
            '2025-01-01',
            '2025-12-31',
            $fiscalYear->id
        );

        $this->assertSame('TT200', $metadata['accounting_regime']);
        $this->assertSame('Thông tư 200/2014/TT-BTC', $metadata['accounting_regime_label']);
    }

    public function test_production_reference_defaults_require_explicit_regime_context(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        $this->grantGlReportPermissions($user);
        Sanctum::actingAs($user);
        Config::set('app.env', 'production');

        $this->postJson('/api/v1/voucher-references/resolve-defaults', [
            'source_voucher_type' => 'CashReceipt',
            'target_voucher_type' => 'SalesInvoice',
            'target_voucher_id' => 999999,
            'standard' => 'TT133',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('fiscal_year_id');
    }

    public function test_production_regime_resolution_does_not_auto_create_a_missing_profile(): void
    {
        $company = Company::create(['name' => 'Regime profile gate', 'tax_code' => 'REGIME-PROFILE-GATE']);
        $fiscalYear = $this->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');
        $fiscalYear->accountingRegimeProfile()->withoutGlobalScope('company')->firstOrFail()->delete();
        Config::set('app.env', 'production');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Hồ sơ chế độ kế toán phải được cung cấp rõ ràng');
        app(AccountingRegimeService::class)->forFiscalYear($company->id, $fiscalYear->id);
    }

    public function test_production_fiscal_year_creation_does_not_auto_create_a_heuristic_profile(): void
    {
        $previousEnv = config('app.env');
        Config::set('app.env', 'production');

        try {
            $company = Company::create(['name' => 'Regime onboarding gate', 'tax_code' => 'REGIME-ONBOARDING-GATE']);
            $fiscalYear = $this->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');

            $this->assertDatabaseMissing('accounting_regime_profiles', [
                'company_id' => $company->id,
                'fiscal_year_id' => $fiscalYear->id,
            ]);

            $this->expectException(ValidationException::class);
            app(AccountingRegimeService::class)->forFiscalYear($company->id, $fiscalYear->id);
        } finally {
            Config::set('app.env', $previousEnv);
        }
    }

    private function createFiscalYear(
        Company $company,
        int $year,
        string $startDate,
        string $endDate
    ): FiscalYear {
        return FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'year' => $year,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'open',
        ]);
    }
}
