<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreBankAccountRequest extends TenantAccountingRequest
{
    public function rules()
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'account_number' => [$required, 'string', 'max:50'],
            'bank_name' => [$required, 'string', 'max:100'],
            'bank_code' => 'nullable|string|max:50',
            'province' => 'nullable|string|max:100',
            'branch' => 'nullable|string|max:100',
            'branch_address' => 'nullable|string|max:255',
            'swift_code' => 'nullable|string|max:50',
            'account_holder' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }
}
