<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\SalesInvoice;
use App\Services\ApprovalWorkflowService;
use App\Services\SalesInvoicePostingApprovalGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Narrow sales approval evidence projection. It deliberately offers no
 * approval decision or posting action; those remain guarded server workflows.
 */
final class SalesInvoiceApprovalController extends Controller
{
    public function __construct(private readonly ApprovalWorkflowService $workflow) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $companyId = (int) ($request->user()->company_id ?? 0);
        if ($companyId <= 0) {
            abort(404);
        }

        $invoice = SalesInvoice::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with('lines')
            ->findOrFail($id);

        return response()->json(['data' => [
            'invoice_id' => $invoice->id,
            'is_posted' => (bool) $invoice->is_posted,
            'snapshot_hash' => SalesInvoicePostingApprovalGate::snapshotHash($invoice),
            'requests' => ApprovalRequest::withoutGlobalScope('company')->with(['steps.decisions'])
                ->where('company_id', $invoice->company_id)
                ->where('approval_key', SalesInvoicePostingApprovalGate::APPROVAL_KEY)
                ->where('subject_type', SalesInvoicePostingApprovalGate::SUBJECT_TYPE)
                ->where('subject_id', (string) $invoice->id)->latest('id')->get()
                ->map(fn (ApprovalRequest $item) => $this->present($item, $invoice))->values(),
            'limitation' => 'This screen records and displays approval evidence only. It cannot approve, post, or override server maker-checker controls.',
        ]]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reference_note' => ['nullable', 'string', 'max:1000']]);
        $companyId = (int) ($request->user()->company_id ?? 0);
        if ($companyId <= 0) {
            abort(404);
        }

        $invoice = SalesInvoice::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with('lines')
            ->findOrFail($id);
        if ($invoice->is_posted) {
            throw ValidationException::withMessages(['invoice' => 'A posted sales invoice cannot enter a new approval workflow. Use the controlled correction/reversal workflow.']);
        }
        $snapshot = SalesInvoicePostingApprovalGate::snapshotHash($invoice);

        $approval = DB::transaction(function () use ($request, $invoice, $snapshot, $data): ApprovalRequest {
            // Keep the tenant predicate on the lock query itself.  The
            // initial global-scoped read is not a sufficient boundary once
            // this query deliberately removes that scope; otherwise a
            // cross-tenant id can be locked before the defensive check below.
            $locked = SalesInvoice::withoutGlobalScope('company')
                ->where('company_id', (int) $request->user()->company_id)
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($invoice->id);
            if ((int) $locked->company_id !== (int) $request->user()->company_id) {
                abort(404);
            }
            if ($locked->is_posted) {
                throw ValidationException::withMessages(['invoice' => 'The sales invoice was posted before this approval request could be created.']);
            }
            $lockedSnapshot = SalesInvoicePostingApprovalGate::snapshotHash($locked);
            if (! hash_equals($snapshot, $lockedSnapshot)) {
                throw ValidationException::withMessages(['approval' => 'The sales invoice changed while the request was being prepared. Reload it and submit the new version.']);
            }
            $pendingCurrent = ApprovalRequest::withoutGlobalScope('company')->lockForUpdate()
                ->where('company_id', $locked->company_id)->where('approval_key', SalesInvoicePostingApprovalGate::APPROVAL_KEY)
                ->where('subject_type', SalesInvoicePostingApprovalGate::SUBJECT_TYPE)->where('subject_id', (string) $locked->id)
                ->where('status', 'pending')->get()->contains(fn (ApprovalRequest $item) => hash_equals((string) data_get($item->request_evidence, 'sales_invoice_snapshot_hash', ''), $lockedSnapshot));
            if ($pendingCurrent) {
                throw ValidationException::withMessages(['approval' => 'A current approval request is already pending for this sales invoice. Wait for a reviewer decision instead of submitting a duplicate.']);
            }

            return $this->workflow->request($request->user(), SalesInvoicePostingApprovalGate::APPROVAL_KEY, SalesInvoicePostingApprovalGate::SUBJECT_TYPE, $locked->id, [
                'reference_document_type' => SalesInvoicePostingApprovalGate::SUBJECT_TYPE,
                'reference_document_id' => $locked->id,
                'sales_invoice_snapshot_hash' => $lockedSnapshot,
                'reference_note' => $data['reference_note'] ?? null,
            ]);
        });

        return response()->json(['data' => $this->present($approval->load('steps.decisions'), $invoice)], 201);
    }

    /** @return array<string,mixed> */
    private function present(ApprovalRequest $request, SalesInvoice $invoice): array
    {
        $evidence = is_array($request->request_evidence) ? $request->request_evidence : [];
        $storedHash = (string) ($evidence['sales_invoice_snapshot_hash'] ?? '');

        return [
            'id' => $request->id, 'status' => $request->status, 'approval_key' => $request->approval_key,
            'policy' => $request->policy_snapshot, 'separation_of_duties_required' => (bool) $request->separation_of_duties_required,
            'requested_by' => $request->requested_by, 'requested_at' => $request->requested_at?->toISOString(),
            'resolved_by' => $request->resolved_by, 'resolved_at' => $request->resolved_at?->toISOString(),
            'evidence' => [
                'reference_document_type' => $evidence['reference_document_type'] ?? null,
                'reference_document_id' => $evidence['reference_document_id'] ?? null,
                'reference_note' => $evidence['reference_note'] ?? null, 'snapshot_hash' => $storedHash,
                'is_current' => $storedHash !== '' && hash_equals($storedHash, SalesInvoicePostingApprovalGate::snapshotHash($invoice)),
            ],
            'steps' => $request->steps->sortBy('step_order')->map(fn ($step) => [
                'step_order' => $step->step_order, 'status' => $step->status, 'required_approvals' => $step->required_approvals,
                'approved_count' => $step->decisions->where('decision', 'approved')->count(),
                'rejected_count' => $step->decisions->where('decision', 'rejected')->count(), 'completed_at' => $step->completed_at?->toISOString(),
            ])->values(),
        ];
    }
}
