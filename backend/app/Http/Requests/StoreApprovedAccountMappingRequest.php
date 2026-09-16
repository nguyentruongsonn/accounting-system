<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The owner supplies the business context and account choice explicitly.
 * This request deliberately has no default account codes or TT99 assumptions.
 */
class StoreApprovedAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'accounting_policy_version_id' => ['required', 'integer'],
            'mapping_key' => ['required', 'string', 'max:120'],
            'mapping_context' => ['present', 'array'],
            'account_role' => ['required', 'string', 'max:80'],
            'account_code' => ['required', 'string', 'max:30'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['required', 'date', 'after_or_equal:effective_from'],
            'regulatory_dependencies' => ['present', 'array'],
            'regulatory_dependencies.*' => ['string', 'max:500'],
            // Lifecycle fields are server-owned and may never be supplied by a maker.
            'status' => ['prohibited'], 'created_by' => ['prohibited'], 'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'], 'contract_hash' => ['prohibited'], 'context_hash' => ['prohibited'],
        ];
    }
}
