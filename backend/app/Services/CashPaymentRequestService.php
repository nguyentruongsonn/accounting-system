<?php

namespace App\Services;

use App\Models\CashPaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashPaymentRequestService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {
    }

    public function getAll(array $filters = [])
    {
        $companyId = $this->requireCompanyId($filters['company_id'] ?? null);
        $query = CashPaymentRequest::query()
            ->where('company_id', $companyId)
            ->orderByDesc('request_date')
            ->orderByDesc('id');

        if (! empty($filters['from_date'])) $query->whereDate('request_date', '>=', $filters['from_date']);
        if (! empty($filters['to_date'])) $query->whereDate('request_date', '<=', $filters['to_date']);
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($builder) use ($search): void {
                $builder->where('request_number', 'like', "%{$search}%")
                    ->orWhere('requester_name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        return $query->get();
    }

    public function getById(int $id, ?int $companyId = null): CashPaymentRequest
    {
        $companyId = $this->requireCompanyId($companyId);

        return CashPaymentRequest::where('company_id', $companyId)->findOrFail($id);
    }

    public function create(array $data): CashPaymentRequest
    {
        $companyId = $this->requireCompanyId($data['company_id'] ?? null);
        $this->periodGuard->assertOpen($companyId, $data['request_date'], 'tạo đề nghị chi tiền');

        return DB::transaction(function () use ($data, $companyId): CashPaymentRequest {
            $request = CashPaymentRequest::create([
                'company_id' => $companyId,
                'request_number' => $data['request_number'],
                'request_date' => $data['request_date'],
                'requester_name' => $data['requester_name'],
                'department' => $data['department'] ?? null,
                'reason' => $data['reason'],
                'amount' => $data['amount'],
                'deadline' => $data['deadline'] ?? null,
                'status' => 'draft',
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);
            $this->auditService->record($request, 'cash_payment_request.created', [], $request->toArray(), null, [
                'domain' => 'cash_payment_request',
                'workflow' => 'draft_only_no_voucher_link',
            ]);

            return $request;
        });
    }

    public function update(int $id, array $data, ?int $companyId = null): CashPaymentRequest
    {
        $companyId = $this->requireCompanyId($companyId ?? ($data['company_id'] ?? null));
        return DB::transaction(function () use ($id, $data, $companyId): CashPaymentRequest {
            $request = CashPaymentRequest::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($request->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Chỉ được sửa đề nghị chi đang ở trạng thái nháp.']);
            }
            $date = $data['request_date'] ?? $request->request_date;
            $this->periodGuard->assertOpen($companyId, $date, 'sửa đề nghị chi tiền');
            $before = $request->toArray();
            $request->fill([
                'request_number' => $data['request_number'] ?? $request->request_number,
                'request_date' => $date,
                'requester_name' => $data['requester_name'] ?? $request->requester_name,
                'department' => array_key_exists('department', $data) ? $data['department'] : $request->department,
                'reason' => $data['reason'] ?? $request->reason,
                'amount' => $data['amount'] ?? $request->amount,
                'deadline' => array_key_exists('deadline', $data) ? $data['deadline'] : $request->deadline,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);
            $request->save();
            $this->auditService->record($request, 'cash_payment_request.updated', $before, $request->toArray());

            return $request;
        });
    }

    public function submit(int $id, ?int $companyId = null): CashPaymentRequest
    {
        $companyId = $this->requireCompanyId($companyId);
        return DB::transaction(function () use ($id, $companyId): CashPaymentRequest {
            $request = CashPaymentRequest::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($request->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Chỉ được gửi duyệt đề nghị chi đang ở trạng thái nháp.']);
            }
            $this->periodGuard->assertOpen($companyId, $request->request_date, 'gửi đề nghị chi tiền');
            $before = $request->toArray();
            $request->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_by' => auth()->id()]);
            $this->auditService->record($request, 'cash_payment_request.submitted', $before, $request->fresh()->toArray(), null, [
                'workflow' => 'approval_required',
                'voucher_linked' => false,
            ]);

            return $request->refresh();
        });
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        DB::transaction(function () use ($id, $companyId): void {
            $request = CashPaymentRequest::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($request->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Chỉ được xóa đề nghị chi đang ở trạng thái nháp.']);
            }
            $this->periodGuard->assertOpen($companyId, $request->request_date, 'xóa đề nghị chi tiền');
            $before = $request->toArray();
            $request->delete();
            $this->auditService->record($request, 'cash_payment_request.deleted', $before, []);
        });
    }

    private function requireCompanyId(?int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolvedCompanyId = $companyId ?? $actorCompanyId;
        if ($resolvedCompanyId === null || (int) $resolvedCompanyId <= 0) {
            throw ValidationException::withMessages(['company_id' => 'An authenticated company context is required.']);
        }
        if ($actorCompanyId !== null && (int) $resolvedCompanyId !== (int) $actorCompanyId) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }

        return (int) $resolvedCompanyId;
    }
}
