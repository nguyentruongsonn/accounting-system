<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreCashPaymentRequestRequest extends TenantAccountingRequest
{
    public function rules(): array
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'request_number' => [$required, 'string', 'max:50'],
            'request_date' => [$required, 'date'],
            'requester_name' => [$required, 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'reason' => [$required, 'string', 'max:5000'],
            'amount' => [$required, 'numeric', 'gt:0'],
            'deadline' => ['nullable', 'date'],
        ];
    }
}
