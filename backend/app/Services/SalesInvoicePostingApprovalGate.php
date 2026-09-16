<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\SalesInvoice;
use Illuminate\Validation\ValidationException;

/**
 * Fail-closed approval boundary for sales posting.  It resolves the
 * tenant-bound workflow from the invoice itself; an API caller can never
 * select a favourable approval request by id.
 */
final class SalesInvoicePostingApprovalGate
{
    public const APPROVAL_KEY = 'sales.invoice.post';
    public const SUBJECT_TYPE = 'sales_invoice';

    /** @return array<string,mixed> */
    public function requireApproved(SalesInvoice $invoice, ?int $postingActorId): array
    {
        $requests = ApprovalRequest::withoutGlobalScope('company')
            ->with(['steps.decisions'])
            ->where('company_id', $invoice->company_id)
            ->where('approval_key', self::APPROVAL_KEY)
            ->where('subject_type', self::SUBJECT_TYPE)
            ->where('subject_id', (string) $invoice->id)
            ->where('status', 'approved')
            ->lockForUpdate()
            ->get();

        $valid = $requests->filter(fn (ApprovalRequest $request): bool => $this->isValid($request, $invoice))->values();
        if ($valid->count() !== 1) {
            throw ValidationException::withMessages([
                'approval' => 'Sales posting requires exactly one completed, current approval request with reference-document evidence.',
            ]);
        }

        /** @var ApprovalRequest $request */
        $request = $valid->sole();
        if ($request->separation_of_duties_required) {
            if ($postingActorId === null) {
                throw ValidationException::withMessages(['approval' => 'A verified posting actor is required by the sales maker-checker policy.']);
            }
            if ((int) $request->requested_by === $postingActorId) {
                throw ValidationException::withMessages(['approval' => 'The sales approval requester cannot post their own document.']);
            }
        }

        return [
            'approval_request_id' => (int) $request->id,
            'approval_policy_id' => (int) $request->approval_policy_id,
            'approval_key' => $request->approval_key,
            'policy_snapshot' => $request->policy_snapshot,
            'requested_by' => (int) $request->requested_by,
            'resolved_by' => (int) $request->resolved_by,
            'resolved_at' => $request->resolved_at?->toAtomString(),
            'document_snapshot_hash' => self::snapshotHash($invoice),
            'reference_document_type' => $request->request_evidence['reference_document_type'],
            'reference_document_id' => $request->request_evidence['reference_document_id'],
            'decision_hashes' => $request->steps->flatMap(fn ($step) => $step->decisions)
                ->where('decision', 'approved')->pluck('evidence_hash')->values()->all(),
        ];
    }

    public static function snapshotHash(SalesInvoice $invoice): string
    {
        if ($invoice->exists) {
            $invoice = $invoice->fresh(['lines']) ?? $invoice;
        }
        $invoice->loadMissing('lines');
        $fields = [
            'company_id', 'customer_id', 'customer_name', 'customer_address', 'receiver_name', 'employee_id', 'employee_name',
            'voucher_type', 'payment_method', 'invoice_number', 'invoice_symbol', 'invoice_code', 'delivery_voucher_number',
            'invoice_date', 'accounting_date', 'due_date', 'sub_total', 'discount_amount', 'tax_amount', 'total_amount',
            'status', 'payment_status', 'description', 'attached_docs', 'currency', 'exchange_rate',
            'functional_currency_code', 'functional_total_amount_raw', 'functional_total_amount_scale',
            'original_total_amount_raw', 'original_total_amount_scale', 'is_export_slip', 'referenced_vouchers',
        ];
        $lineFields = [
            'id', 'item_id', 'description', 'unit', 'warehouse_id', 'warehouse_code', 'debit_account', 'credit_account',
            'inventory_account', 'cogs_account', 'cogs_debit_account', 'cogs_credit_account', 'cogs_price', 'cogs_unit_price',
            'cogs_amount', 'quantity', 'unit_price', 'amount', 'discount_rate', 'discount_amount', 'tax_rate', 'tax_amount',
            'tax_account', 'vat_group', 'order_id', 'contract_id',
        ];
        $payload = [
            'schema' => 'sales-invoice-approval-snapshot/v1',
            'invoice' => self::rawFields($invoice, $fields),
            'lines' => $invoice->lines->sortBy('id')->map(fn ($line) => self::rawFields($line, $lineFields))->values()->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function isValid(ApprovalRequest $request, SalesInvoice $invoice): bool
    {
        $evidence = $request->request_evidence;
        if (! is_array($evidence)
            || ! is_string($evidence['reference_document_type'] ?? null)
            || trim($evidence['reference_document_type']) === ''
            || filter_var($evidence['reference_document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || ! is_string($evidence['sales_invoice_snapshot_hash'] ?? null)
            || ! hash_equals(self::snapshotHash($invoice), $evidence['sales_invoice_snapshot_hash'])) {
            return false;
        }
        if (! $request->resolved_at || ! $request->resolved_by || ! $request->requested_by || $request->steps->isEmpty()) return false;
        foreach ($request->steps as $step) {
            if ($step->status !== 'approved') return false;
            $approvals = $step->decisions->where('decision', 'approved');
            if ($approvals->count() < $step->required_approvals) return false;
            if ($request->separation_of_duties_required && $approvals->contains('decided_by', $request->requested_by)) return false;
        }
        return true;
    }

    /** @param list<string> $fields @return array<string,mixed> */
    private static function rawFields(object $model, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = method_exists($model, 'getRawOriginal') ? $model->getRawOriginal($field) : $model->{$field};
        }
        return $result;
    }
}
