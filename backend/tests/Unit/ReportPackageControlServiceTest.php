<?php

namespace Tests\Unit;

use App\Models\ReportRun;
use App\Services\ReportPackageControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportPackageControlServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_balance_controls_use_leaf_rows_and_exact_decimal_arithmetic(): void
    {
        $controls = app(ReportPackageControlService::class)->evaluate('trial_balance', [
            [
                'is_parent' => false,
                'opening_debit' => '90071992547409.91', 'opening_credit' => '0.00',
                'arising_debit' => '0.09', 'arising_credit' => '100.00',
                'ending_debit' => '90071992547310.00', 'ending_credit' => '0.00',
            ],
            // A parent is an aggregate display row and must not be double-counted.
            [
                'is_parent' => true,
                'opening_debit' => '90071992547409.91', 'opening_credit' => '0.00',
                'arising_debit' => '0.09', 'arising_credit' => '100.00',
                'ending_debit' => '90071992547310.00', 'ending_credit' => '0.00',
            ],
        ]);

        $this->assertSame('report-package-controls.v1', $controls['schema']);
        $this->assertTrue($controls['non_certifying']);
        $this->assertTrue($controls['not_a_close_gate']);
        $this->assertSame('fail', $this->check($controls, 'trial-balance-opening-debit-equals-credit')['status']);
        $this->assertSame('pass', $this->check($controls, 'trial-balance-leaf-roll-forward')['status']);
        $this->assertSame('90071992547409.91', $this->check($controls, 'trial-balance-opening-debit-equals-credit')['details']['expected']);
    }

    public function test_balance_sheet_and_income_statement_cross_foots_are_informational(): void
    {
        $service = app(ReportPackageControlService::class);
        $balance = $service->evaluate('balance_sheet', [
            'assets' => [['end_balance' => '100.00']],
            'liabilities' => [['end_balance' => '60.00']],
            'equity' => [['end_balance' => '40.00']],
        ]);
        $income = $service->evaluate('income_statement', [
            ['code' => '20', 'this_period' => '100.00', 'prev_period' => '70.00'],
            ['code' => '21', 'this_period' => '10.00', 'prev_period' => '5.00'],
            ['code' => '22', 'this_period' => '20.00', 'prev_period' => '5.00'],
            ['code' => '25', 'this_period' => '30.00', 'prev_period' => '10.00'],
            ['code' => '26', 'this_period' => '10.00', 'prev_period' => '10.00'],
            ['code' => '30', 'this_period' => '50.00', 'prev_period' => '50.00'],
            ['code' => '50', 'this_period' => '55.00', 'prev_period' => '55.00'],
            ['code' => '51', 'this_period' => '5.00', 'prev_period' => '5.00'],
            ['code' => '60', 'this_period' => '50.00', 'prev_period' => '50.00'],
        ]);

        $this->assertSame('pass', $this->check($balance, 'balance-sheet-end-assets-equals-liabilities-plus-equity')['status']);
        $this->assertSame('pass', $this->check($income, 'income-statement-this_period-net-profit')['status']);
        $this->assertSame('pass', $this->check($income, 'income-statement-prev_period-profit-after-tax')['status']);
    }

    public function test_integrity_verifier_accepts_the_prior_report_run_control_contract(): void
    {
        $run = new ReportRun([
            'company_id' => 1,
            'report' => 'trial_balance',
            'delivery' => 'view',
            'filters' => [],
            'period' => [],
            'regime' => [],
            'output_hash' => hash('sha256', '[]'),
            'snapshot' => ['schema' => 'canonical-logical-json.v1', 'data' => []],
        ]);
        $run->control_hash = hash('sha256', json_encode([
            'schema' => 'report-run.v1', 'company_id' => 1, 'report' => 'trial_balance', 'delivery' => 'view',
            'filters' => [], 'period' => [], 'regime' => [], 'output_contract' => 'canonical-logical-json.v1',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        $integrity = app(ReportPackageControlService::class)->verifyStoredRun($run);
        $this->assertSame('pass', $integrity['checks'][0]['status']);
        $this->assertSame('pass', $integrity['checks'][1]['status']);
    }

    /** @param array<string, mixed> $controls @return array<string, mixed> */
    private function check(array $controls, string $id): array
    {
        foreach ($controls['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
        $this->fail("Missing control [{$id}].");
    }
}
