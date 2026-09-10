<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PeriodService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly InventoryValuationRunInvalidator $valuationRunInvalidator,
        private readonly AuditService $auditService,
    ) {}

    public function getAllForCompany(int $companyId)
    {
        $companyId = $this->requireCompanyId($companyId);

        return Period::query()
            ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('start_date', 'desc')
            ->get();
    }

    public function createForCompany(int $companyId, array $data): Period
    {
        $companyId = $this->requireCompanyId($companyId);
        $fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->find($data['fiscal_year_id']);

        if ($fiscalYear === null) {
            throw new ModelNotFoundException;
        }

        return Period::create([
            ...$data,
            'fiscal_year_id' => $fiscalYear->id,
            'is_closed' => false,
            'status' => 'open',
        ]);
    }

    /**
     * Compatibility wrapper for trusted in-process callers. HTTP callers must
     * use the controller, which supplies an authenticated tenant explicitly.
     */
    public function create(array $data): Period
    {
        $companyId = auth()->user()?->company_id;

        if ($companyId === null) {
            throw new ModelNotFoundException;
        }

        return $this->createForCompany((int) $companyId, $data);
    }

    /**
     * A period is evidence of a calendar range, not proof that a closing
     * journal was generated. Closing it directly would bypass the server-side
     * accounting workflow in PeriodClosingService.
     */
    public function rejectDirectClose(int $companyId, int $periodId): never
    {
        $companyId = $this->requireCompanyId($companyId);
        $period = Period::query()
            ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
            ->find($periodId);

        if ($period === null) {
            throw new ModelNotFoundException;
        }

        throw new ConflictHttpException(
            'Đóng kỳ phải thực hiện qua quy trình kết chuyển do máy chủ sinh tại closing-entries/execute.'
        );
    }

    /**
     * Reopen a closed period while preserving the original close journal and
     * appending controlled reversal evidence for every active close entry.
     *
     * @return array{period:Period,reversal_entry_ids:list<int>,invalidated_valuation_runs:int}
     */
    public function reopenForCompany(int $companyId, int $periodId, string $reason): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $actor = auth()->user();
        if ($actor === null || ! $actor->hasRole('admin')) {
            throw new AuthorizationException('Chỉ admin được mở lại kỳ kế toán.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Phải nêu lý do mở lại kỳ kế toán.',
            ]);
        }

        return DB::transaction(function () use ($companyId, $periodId, $reason): array {
            $period = Period::query()
                ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
                ->lockForUpdate()
                ->find($periodId);
            if ($period === null) {
                throw new ModelNotFoundException;
            }
            if (! $period->is_closed || $period->status !== 'closed') {
                throw new ConflictHttpException('Chỉ được mở lại kỳ kế toán đang ở trạng thái đã đóng.');
            }

            $before = $period->toArray();
            $period->update([
                'status' => 'open',
                'is_closed' => false,
                'updated_by' => auth()->id(),
            ]);

            $closingEntries = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('voucher_type', 'period_closing')
                ->where('status', 'posted')
                ->whereNull('reversal_of_id')
                ->whereNull('reversed_by_entry_id')
                ->whereDate('posting_date', '>=', $period->start_date->toDateString())
                ->whereDate('posting_date', '<=', $period->end_date->toDateString())
                ->lockForUpdate()
                ->get();

            $reversalEntryIds = [];
            foreach ($closingEntries as $closingEntry) {
                $reversal = $this->journalEntryService->reverse(
                    $closingEntry->id,
                    $companyId,
                    'Mở lại kỳ: '.$reason,
                    $closingEntry->posting_date->toDateString(),
                );
                $reversalEntryIds[] = (int) $reversal->id;
            }

            $invalidatedValuationRuns = $this->valuationRunInvalidator->invalidateForInventoryMovement(
                $companyId,
                $period->start_date,
                'period_reopened',
            );
            $readinessSnapshotFloorId = (int) PeriodCloseReadinessSnapshot::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('period_id', $period->id)
                ->max('id');

            $period->refresh();
            $this->auditService->record(
                $period,
                'period.reopened',
                $before,
                $period->toArray(),
                null,
                [
                    'reason' => $reason,
                    'reversal_entry_ids' => $reversalEntryIds,
                    'invalidated_valuation_runs' => $invalidatedValuationRuns,
                    'readiness_snapshot_floor_id' => $readinessSnapshotFloorId,
                    'requires_reconciliation' => true,
                ],
            );

            return [
                'period' => $period,
                'reversal_entry_ids' => $reversalEntryIds,
                'invalidated_valuation_runs' => $invalidatedValuationRuns,
            ];
        });
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }
}
