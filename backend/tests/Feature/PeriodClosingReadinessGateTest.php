<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\PeriodClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodClosingReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_shadow_reconciliation_with_enforcement_off_blocks_closing_and_records_evidence(): void
    {
        $company = Company::create([
            'name' => 'Close Gate Test Co.',
            'tax_code' => '0101234567',
            'address' => 'Ha Noi',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);
        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'name' => 'FY 2026',
            'code' => 'FY2026-CLOSE-GATE',
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_closed' => false,
        ]);
        $period = Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        foreach ([
            ['131', 'Phải thu', 'asset', 'amphibious'],
            ['5111', 'Doanh thu', 'revenue', 'credit'],
            ['911', 'Xác định kết quả', 'equity', 'amphibious'],
            ['4212', 'LN chưa phân phối', 'equity', 'amphibious'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        app(JournalEntryService::class)->create([
            'company_id' => $company->id,
            'voucher_number' => 'CLOSE-GATE-REV',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'status' => 'posted',
            'lines' => [['debit_account' => '131', 'credit_account' => '5111', 'amount' => '100.00']],
        ]);
        ReconciliationRun::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'period_id' => $period->id,
            'basis' => 'shadow',
            'status' => 'completed',
            'idempotency_key' => 'close-gate-shadow-off',
            'request_hash' => str_repeat('a', 64),
            'algorithm_version' => 'gl-shadow-integrity.v1',
            'input_cutoff_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
            'posted_entry_count' => 1,
            'posted_line_count' => 2,
            'total_debit' => '100.00',
            'total_credit' => '100.00',
            'result_count' => 1,
            'failed_result_count' => 0,
            'warning_result_count' => 0,
            'not_available_result_count' => 0,
            'snapshot_hash' => str_repeat('b', 64),
            'snapshot' => ['enforcement' => 'off'],
        ]);

        try {
            app(PeriodClosingService::class)->executeForCompany($company->id, [
                'period_id' => $period->id,
                'from_date' => '2026-08-01',
                'to_date' => '2026-08-31',
                'voucher_number' => 'CLOSE-GATE-01',
                'close_reason' => 'Đối chiếu cuối kỳ',
            ]);
            $this->fail('A shadow reconciliation with enforcement off must not close an accounting period.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Chưa đủ điều kiện khóa kỳ. Hệ thống chưa có bằng chứng đối chiếu được kiểm soát và close gate đang được áp dụng cho kỳ này.',
                $exception->errors()['period_close_readiness'][0]
            );
        }

        $this->assertDatabaseHas('period_close_readiness_snapshots', [
            'company_id' => $company->id,
            'period_id' => $period->id,
            'eligible_to_close' => false,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $company->id,
            'voucher_number' => 'CLOSE-GATE-01',
        ]);
        $this->assertDatabaseHas('periods', [
            'id' => $period->id,
            'is_closed' => false,
            'status' => 'open',
        ]);
    }
}
