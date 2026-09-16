<?php

namespace App\Services;

use Carbon\CarbonImmutable;

final class DashboardTrendService
{
    public function __construct(private readonly FinancialReportService $reports) {}

    /**
     * @return array<int,array<string,string|null>>
     */
    public function build(int $companyId, string $fromDate, string $toDate): array
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $to = CarbonImmutable::parse($toDate)->startOfDay();
        $rows = [];

        for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            $periodStart = $month->greaterThan($from) ? $month : $from;
            $periodEnd = $month->endOfMonth()->lessThan($to) ? $month->endOfMonth() : $to;
            $income = $this->reports->getIncomeStatement($companyId, $periodStart->toDateString(), $periodEnd->toDateString());
            $balance = $this->reports->getBalanceSheet($companyId, $periodEnd->toDateString());

            $rows[] = [
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'label' => $month->format('m/Y'),
                'revenue' => $this->findValue($income, '10', 'this_period'),
                'gross_cost' => $this->findValue($income, '11', 'this_period'),
                'operating_expenses' => $this->sumValues([
                    $this->findValue($income, '25', 'this_period'),
                    $this->findValue($income, '26', 'this_period'),
                ]),
                'profit' => $this->findValue($income, '60', 'this_period'),
                'cash' => $this->findValue($balance['assets'] ?? [], '111', 'end_balance'),
                'bank' => $this->findValue($balance['assets'] ?? [], '112', 'end_balance'),
                'receivables' => $this->findValue($balance['assets'] ?? [], '131', 'end_balance'),
                'payables' => $this->findValue($balance['liabilities'] ?? [], '331', 'end_balance'),
            ];
        }

        return $rows;
    }

    /** @param mixed $rows */
    private function findValue(mixed $rows, string $code, string $field): ?string
    {
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['code'] ?? null) !== $code) {
                continue;
            }

            $value = $row[$field] ?? null;
            return is_string($value) || is_numeric($value) ? (string) $value : null;
        }

        return null;
    }

    /** @param array<int,?string> $values */
    private function sumValues(array $values): ?string
    {
        $available = array_values(array_filter($values, static fn (?string $value): bool => $value !== null));
        if ($available === []) {
            return null;
        }

        $total = '0.00';
        foreach ($available as $value) {
            $total = bcadd($total, $value, 2);
        }

        return $total;
    }
}
