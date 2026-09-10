<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\User;
use App\Services\PeriodClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodTenantCloseControlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private FiscalYear $fiscalYear;

    private FiscalYear $otherFiscalYear;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::findOrFail(1);
        $this->otherCompany = Company::create([
            'name' => 'Other period tenant',
            'tax_code' => 'PERIOD-TENANT-002',
            'address' => 'Test address',
        ]);
        $this->fiscalYear = FiscalYear::where('company_id', $this->company->id)->firstOrFail();
        $this->otherFiscalYear = FiscalYear::create([
            'company_id' => $this->otherCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantGlReportPermissions($this->user);
        Sanctum::actingAs($this->user);

        foreach ([
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '511', 'name' => 'Doanh thu', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '911', 'name' => 'Xác định kết quả', 'type' => 'equity', 'nature' => 'amphibious'],
            ['code' => '4212', 'name' => 'Lợi nhuận năm nay', 'type' => 'equity', 'nature' => 'amphibious'],
        ] as $account) {
            ChartOfAccount::create([
                ...$account,
                'company_id' => $this->company->id,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
    }

    public function test_actor_tenant_wins_over_malicious_company_on_period_reads_and_preview(): void
    {
        $ownPeriod = $this->period($this->fiscalYear, 8);
        $foreignPeriod = $this->period($this->otherFiscalYear, 8);

        $this->getJson('/api/v1/gl/periods?company_id='.$this->otherCompany->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $ownPeriod->id])
            ->assertJsonMissing(['id' => $foreignPeriod->id]);

        $this->getJson('/api/v1/gl/closing-entries/preview?company_id='.$this->otherCompany->id.'&from_date=2026-08-01&to_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.company_id', $this->company->id);
    }

    public function test_foreign_period_is_not_disclosed_and_direct_close_cannot_mark_a_period_closed(): void
    {
        $ownPeriod = $this->period($this->fiscalYear, 8);
        $foreignPeriod = $this->period($this->otherFiscalYear, 8);

        $this->postJson('/api/v1/gl/periods/close', ['period_id' => $foreignPeriod->id])
            ->assertNotFound();
        $this->postJson('/api/v1/gl/periods/close', ['period_id' => $ownPeriod->id])
            ->assertStatus(409);

        $this->assertDatabaseHas('periods', ['id' => $ownPeriod->id, 'is_closed' => false, 'status' => 'open']);
        $this->assertDatabaseHas('periods', ['id' => $foreignPeriod->id, 'is_closed' => false, 'status' => 'open']);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'voucher_type' => 'period_closing',
        ]);
    }

    public function test_unassigned_actor_cannot_access_period_or_closing_workflows(): void
    {
        $unassigned = User::factory()->create(['company_id' => null]);
        $this->grantGlReportPermissions($unassigned);
        Sanctum::actingAs($unassigned);

        $this->getJson('/api/v1/gl/periods')->assertForbidden();
        $this->getJson('/api/v1/gl/closing-entries/preview')->assertForbidden();
        $this->postJson('/api/v1/gl/closing-entries/execute', [])->assertForbidden();
    }

    public function test_direct_preview_rejects_an_authenticated_foreign_company_before_reading_posted_lines(): void
    {
        $this->expectException(ValidationException::class);

        app(PeriodClosingService::class)->preview(
            $this->otherCompany->id,
            '2026-08-01',
            '2026-08-31',
        );
    }

    public function test_server_generated_execute_is_tenant_bound_but_cannot_close_without_readiness(): void
    {
        $ownPeriod = $this->period($this->fiscalYear, 8);
        $foreignPeriod = $this->period($this->otherFiscalYear, 8);
        $this->postRevenue('PERIOD-CLOSE-001');

        // The preview is tenant-scoped even when the caller supplies a foreign
        // company ID.  Posting remains fail-closed until readiness approval.
        $this->getJson('/api/v1/gl/closing-entries/preview?company_id='.$this->otherCompany->id.'&from_date=2026-08-01&to_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.company_id', $this->company->id);

        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->otherCompany->id,
            'period_id' => $ownPeriod->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'close_reason' => 'Đối chiếu và khóa kỳ tháng 8',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('period_close_readiness');

        $this->assertDatabaseHas('periods', ['id' => $ownPeriod->id, 'is_closed' => false, 'status' => 'open']);
        $this->assertDatabaseHas('periods', ['id' => $foreignPeriod->id, 'is_closed' => false, 'status' => 'open']);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'voucher_type' => 'period_closing',
        ]);
    }

    public function test_execute_requires_a_non_empty_close_reason_before_readiness_gate(): void
    {
        $ownPeriod = $this->period($this->fiscalYear, 8);

        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'period_id' => $ownPeriod->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('close_reason')
            ->assertJsonMissingValidationErrors('period_close_readiness');

        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'period_id' => $ownPeriod->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'close_reason' => '   ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('close_reason')
            ->assertJsonMissingValidationErrors('period_close_readiness');

        // Older clients may still send reason; it must satisfy the same
        // requirement and allow the normal readiness gate to run.
        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'period_id' => $ownPeriod->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'reason' => 'Đối chiếu cuối kỳ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('period_close_readiness')
            ->assertJsonMissingValidationErrors('close_reason');

        $this->assertDatabaseHas('periods', [
            'id' => $ownPeriod->id,
            'is_closed' => false,
            'status' => 'open',
        ]);
    }

    private function period(FiscalYear $fiscalYear, int $month): Period
    {
        return Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => $month,
            'period_number' => $month,
            'name' => "Tháng {$month}/2026",
            'start_date' => sprintf('2026-%02d-01', $month),
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
    }

    private function postRevenue(string $voucherNumber): void
    {
        $draft = $this->postJson('/api/v1/gl/journal-entries', [
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Revenue for period close',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 1000000, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 1000000],
            ],
        ])->assertCreated();

        $this->postJson('/api/v1/gl/journal-entries/'.$draft->json('data.id').'/post')->assertOk();
    }
}
