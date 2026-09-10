<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrialBalanceTenantFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_without_chart_of_accounts_never_falls_back_to_another_company(): void
    {
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        ChartOfAccount::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'code' => '1111',
            'name' => 'Cash B',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ]);

        $result = app(FinancialReportService::class)->getTrialBalance($companyA->id);

        $this->assertCount(0, $result);
        $this->assertFalse($result->contains('code', '1111'));
    }

    public function test_direct_report_service_rejects_an_authenticated_foreign_company(): void
    {
        $companyA = Company::create(['name' => 'Authenticated report company']);
        $companyB = Company::create(['name' => 'Foreign report company']);
        $actor = User::factory()->create(['company_id' => $companyA->id]);
        Sanctum::actingAs($actor);

        try {
            app(FinancialReportService::class)->getTrialBalance($companyB->id);
            $this->fail('A direct report service caller must not read a foreign company.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }
    }
}
