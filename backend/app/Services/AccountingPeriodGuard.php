<?php

namespace App\Services;

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

        $periods = $this->periodsForCompany($companyId)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->lockForUpdate()
            ->get(['periods.id', 'periods.is_closed', 'periods.status']);
        $isClosed = $periods->contains(fn (Period $period): bool => $period->is_closed || $period->status === 'closed');

        if ($isClosed) {
            throw new ConflictHttpException(
                "Không thể {$operation}: khoảng {$from} đến {$to} giao với kỳ kế toán đã khóa."
            );
        }
    }

    private function periodsForCompany(int $companyId)
    {
        return Period::query()
            ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId));
    }
}
