<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\PurchaseInvoice;

/**
 * Reports whether a purchase invoice can supply auditable dimension evidence
 * to the generic dimension validator.
 *
 * Purchase invoices currently do not have a typed, tenant-owned dimension
 * value reference on either their header or lines.  Fields such as supplier,
 * warehouse, item, or attached_docs are commercial data, not evidence that a
 * particular AccountingDimensionValue was selected.  This service deliberately
 * refuses to infer such a mapping.  It is a readiness boundary, not a posting
 * fallback and does not weaken the accounting-policy posting gate.
 */
final class PurchaseInvoiceDimensionReadinessService
{
    public function __construct(
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly AccountingDimensionValidationService $dimensionValidator,
        private readonly AccountingDocumentDimensionAssignmentService $assignments,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(PurchaseInvoice $invoice): array
    {
        $postingDate = ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString();

        try {
            $policy = $this->accountingPolicyResolver->requireForVoucher(
                (int) $invoice->company_id,
                $postingDate,
                SystemVoucherType::PURCHASE_INVOICE,
            );
        } catch (AccountingPolicyUnavailableException $exception) {
            return [
                'status' => 'unavailable',
                'can_enforce' => false,
                'posting_date' => $postingDate,
                'reason_code' => 'accounting_policy_unavailable',
                'reason' => $exception->getMessage(),
            ];
        }

        $required = $policy['required_dimensions'];
        if ($required === []) {
            return [
                'status' => 'not_required',
                'can_enforce' => true,
                'posting_date' => $postingDate,
                'policy_id' => $policy['policy_id'],
                'policy_contract_hash' => $policy['contract_hash'],
                'required_dimensions' => [],
                'audit_requirement' => 'The existing purchase_invoice.policy_applied event records the resolved policy lineage.',
            ];
        }

        $evidence = $this->assignments->currentForPurchaseInvoice($invoice);
        if ($evidence->isEmpty()) {
            return [
                'status' => 'unavailable', 'can_enforce' => false, 'posting_date' => $postingDate,
                'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'required_dimensions' => $required,
                'reason_code' => 'typed_dimension_evidence_not_supported',
                'reason' => 'No explicit AccountingDimensionValue selection has been saved for this purchase invoice. No dimension value is inferred from supplier, item, warehouse, or attachments.',
                'audit_requirement' => 'Save a complete explicit assignment snapshot. A later posting gate must revalidate its policy hash, selected values, and source-document state inside the posting transaction.',
            ];
        }
        $first = $evidence->first();
        if ((int) $first->accounting_policy_version_id !== (int) $policy['policy_id'] || ! hash_equals((string) $policy['contract_hash'], (string) $first->policy_contract_hash)) {
            return [
                'status' => 'unavailable', 'can_enforce' => false, 'posting_date' => $postingDate,
                'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'required_dimensions' => $required,
                'assignment_revision' => $first->revision, 'reason_code' => 'dimension_evidence_policy_mismatch',
                'reason' => 'The latest saved dimension snapshot belongs to a different policy contract. Save a new complete explicit snapshot.',
            ];
        }
        try {
            $this->dimensionValidator->assertSatisfied((int) $invoice->company_id, (int) $policy['policy_id'], $postingDate, $evidence->pluck('accounting_dimension_value_id', 'dimension_code')->map(fn ($id) => (int) $id)->all());
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable', 'can_enforce' => false, 'posting_date' => $postingDate,
                'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'required_dimensions' => $required,
                'assignment_revision' => $first->revision, 'reason_code' => 'dimension_evidence_invalid', 'reason' => $exception->getMessage(),
            ];
        }
        return [
            'status' => 'ready', 'can_enforce' => true, 'posting_date' => $postingDate,
            'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'required_dimensions' => $required,
            'assignment_revision' => $first->revision,
            'audit_requirement' => 'Readiness is informational. Posting enforcement remains disabled until a posting gate revalidates this snapshot inside its transaction.',
        ];
    }
}
