<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreCashAdvanceSettlementRequest extends TenantAccountingRequest
{
    public function rules(): array
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'settlement_number' => [$required, 'string', 'max:50'],
            'settlement_date' => [$required, 'date'],
            'employee_id' => ['nullable', 'integer', $this->tenantExists('employees', 'id', true)],
            'employee_name' => [$required, 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'advance_amount' => [$required, 'numeric', 'gt:0'],
            'actual_spent' => [$required, 'numeric', 'gte:0'],
            'reason' => [$required, 'string', 'max:5000'],
        ];
    }
}
