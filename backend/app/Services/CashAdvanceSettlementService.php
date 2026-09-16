<?php

namespace App\Services;

use App\Models\CashAdvanceSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashAdvanceSettlementService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {
    }

    public function getAll(array $filters = []): mixed
    {
        $companyId = $this->requireCompanyId($filters['company_id'] ?? null);
        $query = CashAdvanceSettlement::query()->where('company_id', $companyId)
            ->orderByDesc('settlement_date')->orderByDesc('id');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($builder) use ($search): void {
                $builder->where('settlement_number', 'like', "%{$search}%")
                    ->orWhere('employee_name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }
        return $query->get();
    }

    public function getById(int $id, ?int $companyId = null): CashAdvanceSettlement
    {
        return CashAdvanceSettlement::where('company_id', $this->requireCompanyId($companyId))->findOrFail($id);
    }

    public function create(array $data): CashAdvanceSettlement
    {
        $companyId = $this->requireCompanyId($data['company_id'] ?? null);
        $this->periodGuard->assertOpen($companyId, $data['settlement_date'], 'tạo quyết toán tạm ứng');
        return DB::transaction(function () use ($data, $companyId): CashAdvanceSettlement {
            $settlement = CashAdvanceSettlement::create($this->normalized($data, $companyId) + [
                'status' => 'draft', 'created_by' => $data['created_by'] ?? auth()->id(),
            ]);
            $this->auditService->record($settlement, 'cash_advance_settlement.created', [], $settlement->toArray(), null, ['workflow' => 'draft_only_no_voucher_link']);
            return $settlement;
        });
    }

    public function update(int $id, array $data, ?int $companyId = null): CashAdvanceSettlement
    {
        $companyId = $this->requireCompanyId($companyId ?? ($data['company_id'] ?? null));
        return DB::transaction(function () use ($id, $data, $companyId): CashAdvanceSettlement {
            $settlement = CashAdvanceSettlement::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($settlement->status !== 'draft') throw ValidationException::withMessages(['status' => 'Chỉ được sửa quyết toán tạm ứng ở trạng thái nháp.']);
            $date = $data['settlement_date'] ?? $settlement->settlement_date;
            $this->periodGuard->assertOpen($companyId, $date, 'sửa quyết toán tạm ứng');
            $before = $settlement->toArray();
            $settlement->fill($this->normalized(array_merge($settlement->toArray(), $data), $companyId));
            $settlement->updated_by = $data['updated_by'] ?? auth()->id();
            $settlement->save();
            $this->auditService->record($settlement, 'cash_advance_settlement.updated', $before, $settlement->toArray());
            return $settlement->refresh();
        });
    }

    public function submit(int $id, ?int $companyId = null): CashAdvanceSettlement
    {
        $companyId = $this->requireCompanyId($companyId);
        return DB::transaction(function () use ($id, $companyId): CashAdvanceSettlement {
            $settlement = CashAdvanceSettlement::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($settlement->status !== 'draft') throw ValidationException::withMessages(['status' => 'Chỉ được gửi quyết toán tạm ứng ở trạng thái nháp.']);
            $this->periodGuard->assertOpen($companyId, $settlement->settlement_date, 'gửi quyết toán tạm ứng');
            $before = $settlement->toArray();
            $settlement->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_by' => auth()->id()]);
            $this->auditService->record($settlement, 'cash_advance_settlement.submitted', $before, $settlement->fresh()->toArray(), null, ['workflow' => 'approval_required', 'voucher_linked' => false]);
            return $settlement->refresh();
        });
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        DB::transaction(function () use ($id, $companyId): void {
            $settlement = CashAdvanceSettlement::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($settlement->status !== 'draft') throw ValidationException::withMessages(['status' => 'Chỉ được xóa quyết toán tạm ứng ở trạng thái nháp.']);
            $this->periodGuard->assertOpen($companyId, $settlement->settlement_date, 'xóa quyết toán tạm ứng');
            $before = $settlement->toArray();
            $settlement->delete();
            $this->auditService->record($settlement, 'cash_advance_settlement.deleted', $before, []);
        });
    }

    private function normalized(array $data, int $companyId): array
    {
        $advance = (float) ($data['advance_amount'] ?? 0);
        $actual = (float) ($data['actual_spent'] ?? 0);
        return [
            'company_id' => $companyId,
            'settlement_number' => $data['settlement_number'],
            'settlement_date' => $data['settlement_date'],
            'employee_id' => $data['employee_id'] ?? null,
            'employee_name' => $data['employee_name'],
            'department' => $data['department'] ?? null,
            'advance_amount' => $advance,
            'actual_spent' => $actual,
            'refund_amount' => max($advance - $actual, 0),
            'extra_amount' => max($actual - $advance, 0),
            'reason' => $data['reason'],
        ];
    }

    private function requireCompanyId(?int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolved = $companyId ?? $actorCompanyId;
        if ($resolved === null || (int) $resolved <= 0 || ($actorCompanyId !== null && (int) $resolved !== (int) $actorCompanyId)) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }
        return (int) $resolved;
    }
}
