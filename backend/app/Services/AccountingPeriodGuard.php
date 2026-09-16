<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountingPeriodGuard
{
    public function assertOpen(int $companyId, mixed $postingDate, string $operation): void
    {
        $date = CarbonImmutable::parse($postingDate)->toDateString();

        // Lock the matching period rows when the caller is already in its
        // mutation transaction. Period close locks the same rows, so neither
        // operation can cross the open/closed decision concurrently.
        $periods = $this->periodsForCompany($companyId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->lockForUpdate()
            ->get(['periods.id', 'periods.is_closed', 'periods.status']);

        if ($periods->isEmpty()) {
            if ($this->fiscalYearCompatibilityCovers($companyId, $date, $date)) {
                return;
            }

            throw new ConflictHttpException(
                "Không thể {$operation}: ngày hạch toán {$date} không thuộc kỳ kế toán nào của doanh nghiệp."
            );
        }

        if ($periods->count() > 1) {
            throw new ConflictHttpException(
                "Không thể {$operation}: ngày hạch toán {$date} thuộc nhiều kỳ kế toán chồng lấn."
            );
        }

        $isClosed = $periods->contains(fn (Period $period): bool => $period->is_closed || $period->status === 'closed');

        if ($isClosed) {
            throw new ConflictHttpException(
                "Không thể {$operation}: ngày hạch toán {$date} thuộc kỳ kế toán đã khóa."
            );
        }
    }

    public function assertRangeOpen(int $companyId, mixed $fromDate, mixed $toDate, string $operation): void
    {
        $from = CarbonImmutable::parse($fromDate)->toDateString();
        $to = CarbonImmutable::parse($toDate)->toDateString();

        if ($from > $to) {
            throw new ConflictHttpException(
                "Không thể {$operation}: khoảng {$from} đến {$to} không hợp lệ."
            );
        }

        $periods = $this->periodsForCompany($companyId)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->lockForUpdate()
            ->orderBy('start_date')
            ->get(['periods.id', 'periods.start_date', 'periods.end_date', 'periods.is_closed', 'periods.status']);

        if ($periods->isEmpty()) {
            if ($this->fiscalYearCompatibilityCovers($companyId, $from, $to)) {
                return;
            }

            throw new ConflictHttpException(
                "Không thể {$operation}: khoảng {$from} đến {$to} không thuộc kỳ kế toán nào của doanh nghiệp."
            );
        }

        $cursor = CarbonImmutable::parse($from);
        foreach ($periods as $period) {
            $start = CarbonImmutable::parse($period->start_date);
            $end = CarbonImmutable::parse($period->end_date);

            if ($start->greaterThan($cursor)) {
                throw new ConflictHttpException(
                    "Không thể {$operation}: khoảng {$from} đến {$to} có ngày không thuộc kỳ kế toán nào."
                );
            }

            if ($period->is_closed || $period->status === 'closed') {
                throw new ConflictHttpException(
                    "Không thể {$operation}: khoảng {$from} đến {$to} giao với kỳ kế toán đã khóa."
                );
            }

            if ($end->greaterThanOrEqualTo($cursor)) {
                $cursor = $end->addDay();
            }

            if ($cursor->greaterThan(CarbonImmutable::parse($to))) {
                return;
            }
        }

        if (! $cursor->greaterThan(CarbonImmutable::parse($to))) {
            throw new ConflictHttpException(
                "Không thể {$operation}: khoảng {$from} đến {$to} có ngày không thuộc kỳ kế toán nào."
            );
        }

    }

    private function periodsForCompany(int $companyId)
    {
        return Period::query()
            ->whereHas('fiscalYear', fn ($query) => $query
                // Period checks are also used by direct service callers and
                // queued jobs where auth() may not carry the tenant scope.
                // Keep the explicit tenant predicate as the source of truth
                // while retaining FiscalYear's soft-delete protection.
                ->withoutGlobalScope('company')
                ->where('company_id', $companyId));
    }

    /**
     * Older tenants may have a fiscal year but no generated detail periods.
     * Keep those tenants usable until period setup is completed, while never
     * using the fallback when any explicit period exists for the company.
     */
    private function fiscalYearCompatibilityCovers(int $companyId, string $from, string $to): bool
    {
        if ($this->periodsForCompany($companyId)->exists()) {
            return false;
        }

        return FiscalYear::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $from)
            ->whereDate('end_date', '>=', $to)
            ->where('status', '!=', 'closed')
            ->exists();
    }
}
