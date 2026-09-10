<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\SalesInvoice;

/**
 * Reports only whether immutable, explicit sales dimension evidence exists.
 * It is deliberately neither a commercial-data inference engine nor a
 * substitute for the in-transaction posting gate.
 */
final class SalesInvoiceDimensionReadinessService
{
    public function __construct(
        private readonly AccountingPolicyResolver $policyResolver,
        private readonly AccountingDimensionValidationService $validator,
        private readonly AccountingDocumentDimensionAssignmentService $assignments,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(SalesInvoice $invoice): array
    {
        $postingDate = ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString();
        try {
            $policy = $this->policyResolver->requireForVoucher((int) $invoice->company_id, $postingDate, SystemVoucherType::SALES_INVOICE);
        } catch (AccountingPolicyUnavailableException $exception) {
            return ['status' => 'unavailable', 'can_enforce' => false, 'posting_date' => $postingDate, 'reason_code' => 'accounting_policy_unavailable', 'reason' => $exception->getMessage()];
        }
        $required = $policy['required_dimensions'];
        if ($required === []) return ['status' => 'not_required', 'can_enforce' => true, 'posting_date' => $postingDate, 'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'required_dimensions' => []];

        $evidence = $this->assignments->currentForSalesInvoice($invoice);
        if ($evidence->isEmpty()) return $this->unavailable($postingDate, $policy, $required, null, 'typed_dimension_evidence_not_supported', 'No explicit AccountingDimensionValue selection has been saved for this sales invoice. No dimension value is inferred from customer, item, warehouse, payment method, or attachments.');
        $first = $evidence->first();
        if ((int) $first->accounting_policy_version_id !== (int) $policy['policy_id'] || ! hash_equals((string) $policy['contract_hash'], (string) $first->policy_contract_hash)) {
            return $this->unavailable($postingDate, $policy, $required, $first->revision, 'dimension_evidence_policy_mismatch', 'The latest saved dimension snapshot belongs to a different policy contract. Save a new complete explicit snapshot.');
        }
        try {
            $this->validator->assertSatisfied((int) $invoice->company_id, (int) $policy['policy_id'], $postingDate, $evidence->pluck('accounting_dimension_value_id', 'dimension_code')->map(fn ($id): int => (int) $id)->all());
        } catch (\Throwable $exception) {
            return $this->unavailable($postingDate, $policy, $required, $first->revision, 'dimension_evidence_invalid', $exception->getMessage());
        }
        return ['status' => 'ready', 'can_enforce' => true, 'posting_date' => $postingDate, 'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'required_dimensions' => $required, 'assignment_revision' => $first->revision, 'audit_requirement' => 'Posting must revalidate the locked immutable snapshot in its transaction.'];
    }

    /** @param array<string,mixed> $policy @param array<int,string> $required @return array<string,mixed> */
    private function unavailable(string $postingDate, array $policy, array $required, ?int $revision, string $reasonCode, string $reason): array
    {
        return array_filter(['status' => 'unavailable', 'can_enforce' => false, 'posting_date' => $postingDate, 'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'required_dimensions' => $required, 'assignment_revision' => $revision, 'reason_code' => $reasonCode, 'reason' => $reason], static fn ($value) => $value !== null);
    }
}
