<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApprovedAccountMappingRequest extends FormRequest
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
            'accounting_policy_version_id' => ['sometimes', 'integer'],
            'mapping_key' => ['sometimes', 'string', 'max:120'],
            'mapping_context' => ['sometimes', 'array'],
            'account_role' => ['sometimes', 'string', 'max:80'],
            'account_code' => ['sometimes', 'string', 'max:30'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'date'],
            'regulatory_dependencies' => ['sometimes', 'array'],
            'regulatory_dependencies.*' => ['string', 'max:500'],
            'status' => ['prohibited'], 'created_by' => ['prohibited'], 'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'], 'contract_hash' => ['prohibited'], 'context_hash' => ['prohibited'],
        ];
    }
}
