<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\AccountingDimensionValue;
use App\Models\AccountingPolicyDimensionRequirement;
use App\Models\PurchaseInvoice;

/**
 * Read-only selection context for an explicit purchase-invoice dimension
 * snapshot.  It deliberately exposes only tenant-owned, active values that
 * are effective on the document posting date; it never derives a value from a
 * supplier, item, warehouse, or other commercial field.
 */
final class PurchaseInvoiceDimensionSelectionContextService
{
    public function __construct(private readonly AccountingPolicyResolver $policyResolver) {}

    /** @return array<string, mixed> */
    public function inspect(PurchaseInvoice $invoice): array
    {
        $postingDate = ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString();

        try {
            $policy = $this->policyResolver->requireForVoucher(
                (int) $invoice->company_id,
                $postingDate,
                SystemVoucherType::PURCHASE_INVOICE,
            );
        } catch (AccountingPolicyUnavailableException $exception) {
            return [
                'status' => 'unavailable',
                'posting_date' => $postingDate,
                'reason_code' => 'accounting_policy_unavailable',
                'reason' => $exception->getMessage(),
                'required_dimensions' => [],
            ];
        }

        $required = $policy['required_dimensions'];
        if ($required === []) {
            return [
                'status' => 'not_required',
                'posting_date' => $postingDate,
                'policy_id' => $policy['policy_id'],
                'policy_contract_hash' => $policy['contract_hash'],
                'required_dimensions' => [],
            ];
        }

        $requirements = AccountingPolicyDimensionRequirement::withoutGlobalScope('company')
            ->with('definition')
            ->where('company_id', $invoice->company_id)
            ->where('accounting_policy_version_id', $policy['policy_id'])
            ->where('is_required', true)
            ->whereDate('effective_from', '<=', $postingDate)
            ->whereDate('effective_to', '>=', $postingDate)
            ->get()
            ->keyBy(fn (AccountingPolicyDimensionRequirement $requirement): string => (string) $requirement->definition?->code);

        $dimensions = [];
        foreach ($required as $code) {
            $requirement = $requirements->get($code);
            $definition = $requirement?->definition;
            if ($definition === null || $definition->status !== 'active'
                || $postingDate < $definition->effective_from->toDateString()
                || $postingDate > $definition->effective_to->toDateString()) {
                return $this->unavailable($postingDate, $policy, 'dimension_definition_unavailable', "Required dimension [{$code}] is not an active effective tenant definition.");
            }

            $values = AccountingDimensionValue::withoutGlobalScope('company')
                ->where('company_id', $invoice->company_id)
                ->where('accounting_dimension_definition_id', $definition->id)
                ->where('status', 'active')
                ->whereDate('effective_from', '<=', $postingDate)
                ->whereDate('effective_to', '>=', $postingDate)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'effective_from', 'effective_to']);
            if ($values->isEmpty()) {
                return $this->unavailable($postingDate, $policy, 'dimension_value_unavailable', "Required dimension [{$code}] has no active effective tenant value to select.");
            }

            $dimensions[] = [
                'code' => $definition->code,
                'name' => $definition->name,
                'definition_id' => $definition->id,
                'values' => $values->map(fn (AccountingDimensionValue $value): array => [
                    'id' => $value->id,
                    'code' => $value->code,
                    'name' => $value->name,
                    'effective_from' => $value->effective_from->toDateString(),
                    'effective_to' => $value->effective_to->toDateString(),
                ])->values(),
            ];
        }

        return [
            'status' => 'available',
            'posting_date' => $postingDate,
            'policy_id' => $policy['policy_id'],
            'policy_contract_hash' => $policy['contract_hash'],
            'required_dimensions' => $dimensions,
        ];
    }

    /** @param array<string, mixed> $policy @return array<string, mixed> */
    private function unavailable(string $postingDate, array $policy, string $reasonCode, string $reason): array
    {
        return [
            'status' => 'unavailable',
            'posting_date' => $postingDate,
            'policy_id' => $policy['policy_id'],
            'policy_contract_hash' => $policy['contract_hash'],
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'required_dimensions' => [],
        ];
    }
}
