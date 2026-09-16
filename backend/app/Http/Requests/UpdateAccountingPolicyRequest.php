<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountingPolicyRequest extends FormRequest
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
            'accounting_regime_profile_id' => ['sometimes', 'integer'],
            'policy_key' => ['sometimes', 'string', Rule::in(StoreAccountingPolicyRequest::SUPPORTED_POLICY_KEYS)],
            'policy_version' => ['sometimes', 'string', 'max:80'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'date'],
            'source_accounts' => ['sometimes', 'array', 'min:1'],
            'source_accounts.*.account_code' => ['required_with:source_accounts', 'string', 'max:30'],
            'source_accounts.*.category' => ['required_with:source_accounts', Rule::in(['revenue', 'expense'])],
            'regulatory_dependencies' => ['sometimes', 'array'],
            'regulatory_dependencies.*' => ['string', 'max:500'],
            'posting_rule_contract' => ['prohibited'],
            'required_dimensions' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'contract_hash' => ['prohibited'],
        ];
    }
}
