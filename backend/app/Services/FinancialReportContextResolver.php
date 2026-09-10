<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\FinancialReportContext;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the single accounting context to which a financial-report request
 * belongs.  A report must never silently span fiscal years or use a fiscal
 * year/profile belonging to another tenant.
 */
final class FinancialReportContextResolver
{
    public function __construct(private readonly AccountingRegimeService $accountingRegimeService) {}

    /**
     * @param  'financial_statement'|'general_journal'|'general_ledger'  $reportType
     */
    public function resolve(Request $request, string $reportType = 'financial_statement'): FinancialReportContext
    {
        $companyId = TenantContext::companyId($request);
        if (! Company::query()->whereKey($companyId)->exists()) {
            throw ValidationException::withMessages([
                'company_id' => 'Doanh nghiệp đang hoạt động không tồn tại.',
            ]);
        }

        $providedFrom = $this->parseDate($request->input('from_date'), 'from_date');
        $providedTo = $this->parseDate($request->input('to_date'), 'to_date');
        $fiscalYear = $this->resolveFiscalYear($request, $companyId, $providedFrom, $providedTo);

        [$fromDate, $toDate] = $this->resolveDateRange(
            $fiscalYear,
            $providedFrom,
            $providedTo,
            $reportType,
        );

        if ($fromDate->greaterThan($toDate)) {
            throw ValidationException::withMessages([
                'to_date' => 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.',
            ]);
        }

        if ($fromDate->lessThan($fiscalYear->start_date) || $toDate->greaterThan($fiscalYear->end_date)) {
            throw ValidationException::withMessages([
                'date_range' => 'Khoảng thời gian báo cáo phải nằm trọn trong năm tài chính đã chọn.',
            ]);
        }

        return new FinancialReportContext(
            $companyId,
            $fiscalYear,
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $this->accountingRegimeService->reportMetadata($companyId, null, null, $fiscalYear->id),
        );
    }

    private function resolveFiscalYear(
        Request $request,
        int $companyId,
        ?CarbonImmutable $fromDate,
        ?CarbonImmutable $toDate,
    ): FiscalYear {
        $rawFiscalYearId = $request->input('fiscal_year_id');
        if ($rawFiscalYearId !== null && $rawFiscalYearId !== '') {
            if (filter_var($rawFiscalYearId, FILTER_VALIDATE_INT) === false || (int) $rawFiscalYearId < 1) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => 'Năm tài chính không hợp lệ.',
                ]);
            }

            $fiscalYear = FiscalYear::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->find((int) $rawFiscalYearId);

            if ($fiscalYear === null) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => 'Năm tài chính không thuộc doanh nghiệp hiện tại.',
                ]);
            }

            return $fiscalYear;
        }

        $anchorDate = $toDate ?? $fromDate ?? CarbonImmutable::today();
        $years = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $anchorDate->toDateString())
            ->whereDate('end_date', '>=', $anchorDate->toDateString())
            ->orderByDesc('id')
            ->get();

        if ($years->count() !== 1) {
            if ($years->isEmpty()) {
                $year = (int) $anchorDate->format('Y');
                return FiscalYear::withoutGlobalScope('company')->firstOrCreate(
                    [
                        'company_id' => $companyId,
                        'year' => $year,
                    ],
                    [
                        'start_date' => "{$year}-01-01",
                        'end_date' => "{$year}-12-31",
                        'status' => 'open',
                    ]
                );
            }

            throw ValidationException::withMessages([
                'fiscal_year_id' => 'Có nhiều năm tài chính phù hợp với ngày báo cáo; phải chỉ định fiscal_year_id.',
            ]);
        }

        return $years->first();
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveDateRange(
        FiscalYear $fiscalYear,
        ?CarbonImmutable $providedFrom,
        ?CarbonImmutable $providedTo,
        string $reportType,
    ): array {
        $yearStart = CarbonImmutable::parse($fiscalYear->start_date)->startOfDay();
        $yearEnd = CarbonImmutable::parse($fiscalYear->end_date)->startOfDay();
        // Historical callers omitted dates and the report services consequently
        // evaluated the complete ledger.  Keep that compatibility within the
        // *resolved fiscal year*, never by implicitly crossing a year boundary.
        $defaultTo = $yearEnd;

        if (in_array($reportType, ['general_journal', 'general_ledger'], true)) {
            $today = CarbonImmutable::today();
            $journalDefaultTo = $today->betweenIncluded($yearStart, $yearEnd)
                ? $today->endOfMonth()->min($yearEnd)
                : $yearEnd;
            $journalDefaultFrom = $journalDefaultTo->startOfMonth();
            if ($journalDefaultFrom->lessThan($yearStart)) {
                $journalDefaultFrom = $yearStart;
            }

            return [$providedFrom ?? $journalDefaultFrom, $providedTo ?? $journalDefaultTo];
        }

        return [$providedFrom ?? $yearStart, $providedTo ?? $defaultTo];
    }

    private function parseDate(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages([$field => 'Ngày báo cáo phải có định dạng YYYY-MM-DD.']);
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Ngày báo cáo không hợp lệ.']);
        }

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Ngày báo cáo không hợp lệ.']);
        }

        return $date;
    }
}
