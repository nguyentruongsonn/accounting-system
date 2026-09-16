<?php

namespace App\Services;

use App\Models\ReportRun;
use App\Support\DecimalMoney;

/**
 * Objective arithmetic and evidence controls for an issued logical report.
 *
 * These results deliberately do not establish an Appendix IV mapping, certify
 * a financial statement, approve a reporting package, or make a period
 * eligible for closing.  They only state whether a finite calculation can be
 * reproduced from the exact snapshot that was issued.
 */
class ReportPackageControlService
{
    public const SCHEMA = 'report-package-controls.v1';

    /** @return array<string, mixed> */
    public function evaluate(string $report, mixed $output): array
    {
        $checks = [
            $this->check('report-output-shape', 'pass', 'Logical output was captured for an immutable report-run snapshot.'),
        ];

        if ($report === 'general_journal') {
            $checks[] = $this->journalBalance($output);
        } elseif ($report === 'trial_balance') {
            $checks = [...$checks, ...$this->trialBalanceChecks($output)];
        } elseif ($report === 'balance_sheet') {
            $checks[] = $this->balanceSheetCrossFoot($output);
        } elseif ($report === 'income_statement') {
            $checks = [...$checks, ...$this->incomeStatementCrossFoots($output)];
        } elseif ($report === 'general_ledger') {
            $checks[] = $this->check(
                'ledger-cross-foot-not-applicable',
                'not_applicable',
                'A single-account ledger extract has no package-level debit/credit equality assertion.'
            );
        }

        return [
            'schema' => self::SCHEMA,
            // No owner-approved Appendix IV definition catalog is modelled in
            // this application yet. Null is intentional: it must not be
            // interpreted as a version implicitly selected by code.
            'definition_version' => null,
            'appendix_iv_certified' => false,
            'non_certifying' => true,
            'not_a_close_gate' => true,
            'checks' => $checks,
            'summary' => [
                'passed' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'pass')),
                'failed' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'fail')),
                'not_applicable' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'not_applicable')),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function verifyStoredRun(ReportRun $run): array
    {
        $snapshot = $run->snapshot ?? [];
        $hasLogicalData = is_array($snapshot) && array_key_exists('data', $snapshot);
        $data = $hasLogicalData ? $snapshot['data'] : null;
        $outputHash = $hasLogicalData ? $this->hash($this->canonicalize($data)) : null;
        $control = [
            'schema' => 'report-run.v1',
            'company_id' => $run->company_id,
            'report' => $run->report,
            'delivery' => $run->delivery,
            'filters' => $this->canonicalize($run->filters ?? []),
            'period' => $run->period ?? [],
            'regime' => $this->canonicalize($run->regime ?? []),
            'output_contract' => 'canonical-logical-json.v1',
        ];
        // Runs issued before this non-certification metadata was introduced
        // used the prior canonical-control contract. Verify them against that
        // contract rather than reporting a false tamper signal.
        if (is_array($snapshot) && array_key_exists('definition_version', $snapshot)) {
            $control['definition_version'] = $snapshot['definition_version'];
        }
        if (is_array($snapshot) && array_key_exists('appendix_iv_certified', $snapshot)) {
            $control['appendix_iv_certified'] = (bool) $snapshot['appendix_iv_certified'];
        }
        $controlHash = $this->hash($control);

        return [
            'schema' => 'report-run-integrity.v1',
            'non_certifying' => true,
            'not_a_close_gate' => true,
            'checks' => [
                $this->hashCheck('logical-output-hash', $hasLogicalData, $run->output_hash, $outputHash),
                $this->hashCheck('report-context-control-hash', true, $run->control_hash, $controlHash),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function journalBalance(mixed $rows): array
    {
        $debit = DecimalMoney::ZERO;
        $credit = DecimalMoney::ZERO;
        foreach ($this->rows($rows) as $row) {
            $debit = DecimalMoney::add($debit, $row['debit_amount'] ?? '0.00');
            $credit = DecimalMoney::add($credit, $row['credit_amount'] ?? '0.00');
        }

        return $this->amountCheck('general-journal-debit-equals-credit', $debit, $credit);
    }

    /** @return array<int, array<string, mixed>> */
    private function trialBalanceChecks(mixed $rows): array
    {
        $totals = [
            'opening_debit' => DecimalMoney::ZERO, 'opening_credit' => DecimalMoney::ZERO,
            'arising_debit' => DecimalMoney::ZERO, 'arising_credit' => DecimalMoney::ZERO,
            'ending_debit' => DecimalMoney::ZERO, 'ending_credit' => DecimalMoney::ZERO,
        ];
        $rowFailures = 0;
        foreach ($this->rows($rows) as $row) {
            // Parent rows are display aggregates; including them would double count.
            if (($row['is_parent'] ?? false) === true) {
                continue;
            }
            foreach (array_keys($totals) as $key) {
                $totals[$key] = DecimalMoney::add($totals[$key], $row[$key] ?? '0.00');
            }
            $openingNet = DecimalMoney::subtract($row['opening_debit'] ?? '0.00', $row['opening_credit'] ?? '0.00');
            $movementNet = DecimalMoney::subtract($row['arising_debit'] ?? '0.00', $row['arising_credit'] ?? '0.00');
            $endingNet = DecimalMoney::subtract($row['ending_debit'] ?? '0.00', $row['ending_credit'] ?? '0.00');
            if (DecimalMoney::compare(DecimalMoney::add($openingNet, $movementNet), $endingNet) !== 0) {
                $rowFailures++;
            }
        }

        return [
            $this->amountCheck('trial-balance-opening-debit-equals-credit', $totals['opening_debit'], $totals['opening_credit']),
            $this->amountCheck('trial-balance-movement-debit-equals-credit', $totals['arising_debit'], $totals['arising_credit']),
            $this->amountCheck('trial-balance-ending-debit-equals-credit', $totals['ending_debit'], $totals['ending_credit']),
            $this->check(
                'trial-balance-leaf-roll-forward',
                $rowFailures === 0 ? 'pass' : 'fail',
                'Each leaf row satisfies opening net plus movement net equals ending net.',
                ['failed_leaf_rows' => $rowFailures]
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function balanceSheetCrossFoot(mixed $output): array
    {
        $data = is_array($output) ? $output : [];
        $assets = $this->sumField($data['assets'] ?? [], 'end_balance');
        $liabilitiesAndEquity = DecimalMoney::add(
            $this->sumField($data['liabilities'] ?? [], 'end_balance'),
            $this->sumField($data['equity'] ?? [], 'end_balance')
        );

        return $this->amountCheck('balance-sheet-end-assets-equals-liabilities-plus-equity', $assets, $liabilitiesAndEquity);
    }

    /** @return array<int, array<string, mixed>> */
    private function incomeStatementCrossFoots(mixed $rows): array
    {
        $byCode = [];
        foreach ($this->rows($rows) as $row) {
            $byCode[(string) ($row['code'] ?? '')] = $row;
        }
        $checks = [];
        foreach (['this_period', 'prev_period'] as $column) {
            $checks[] = $this->amountCheck(
                "income-statement-{$column}-net-profit",
                DecimalMoney::subtract(
                    DecimalMoney::add($byCode['20'][$column] ?? '0.00', $byCode['21'][$column] ?? '0.00'),
                    DecimalMoney::sum([$byCode['22'][$column] ?? '0.00', $byCode['25'][$column] ?? '0.00', $byCode['26'][$column] ?? '0.00'])
                ),
                $byCode['30'][$column] ?? '0.00'
            );
            $checks[] = $this->amountCheck(
                "income-statement-{$column}-profit-after-tax",
                DecimalMoney::subtract($byCode['50'][$column] ?? '0.00', $byCode['51'][$column] ?? '0.00'),
                $byCode['60'][$column] ?? '0.00'
            );
        }

        return $checks;
    }

    /** @return array<string, mixed> */
    private function amountCheck(string $id, mixed $expected, mixed $actual): array
    {
        $expected = DecimalMoney::normalize($expected);
        $actual = DecimalMoney::normalize($actual);
        $difference = DecimalMoney::subtract($actual, $expected);

        return $this->check(
            $id,
            DecimalMoney::compare($difference, DecimalMoney::ZERO) === 0 ? 'pass' : 'fail',
            'Exact fixed-scale arithmetic check over the issued logical output.',
            compact('expected', 'actual', 'difference')
        );
    }

    /** @return array<string, mixed> */
    private function hashCheck(string $id, bool $available, string $expected, ?string $actual): array
    {
        return $this->check(
            $id,
            $available && hash_equals($expected, (string) $actual) ? 'pass' : 'fail',
            'Recomputed SHA-256 from the persisted canonical logical snapshot/context.',
            ['expected_hash' => $expected, 'actual_hash' => $actual]
        );
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private function check(string $id, string $status, string $description, array $details = []): array
    {
        return ['id' => $id, 'status' => $status, 'description' => $description, 'details' => $details];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(mixed $rows): array
    {
        if ($rows instanceof \Traversable) {
            $rows = iterator_to_array($rows);
        }
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $row): array {
            if (is_object($row)) {
                $row = get_object_vars($row);
            }

            return is_array($row) ? $row : [];
        }, $rows), static fn (array $row): bool => $row !== []));
    }

    private function sumField(mixed $rows, string $field): string
    {
        return DecimalMoney::sum(array_map(fn (array $row): mixed => $row[$field] ?? '0.00', $this->rows($rows)));
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $this->canonicalize($value->jsonSerialize());
        }
        if ($value instanceof \Traversable) {
            return $this->canonicalize(iterator_to_array($value));
        }
        if (is_object($value)) {
            return $this->canonicalize(get_object_vars($value));
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->canonicalize($item);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
