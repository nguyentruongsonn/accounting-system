<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreInventoryIssueRequest extends TenantAccountingRequest
{
    public function rules()
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'voucher_type' => 'nullable|string',
            'voucher_number' => 'nullable|string',
            'voucher_date' => 'nullable|date',
            'posting_date' => 'nullable|date',
            'contact_type' => 'nullable|string',
            'contact_id' => ['nullable', $this->tenantContactExists()],
            'contact_name' => 'nullable|string',
            'receiver_name' => 'nullable|string|max:255',
            'receiver_address' => 'nullable|string|max:255',
            'employee_id' => ['nullable', 'integer', $this->tenantExists('employees', 'id', true)],
            'employee_name' => 'nullable|string|max:255',
            'warehouse_id' => ['nullable', 'integer', $this->tenantExists('warehouses')],
            'description' => 'nullable|string',
            'attached_docs' => 'nullable|string',
            'currency' => 'nullable|string|max:10',
            'exchange_rate' => 'nullable|numeric|min:0',
            'referenced_vouchers' => 'nullable|array',
            'referenced_vouchers.*' => ['array', $this->tenantVoucherReferenceExists()],
            'lines' => [$required, 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', $this->tenantExists('items')],
            'lines.*.unit' => 'nullable|string',
            'lines.*.warehouse_id' => ['nullable', 'integer', $this->tenantExists('warehouses')],
            'lines.*.warehouse_code' => ['nullable', 'string', $this->tenantExists('warehouses', 'code')],
            'lines.*.description' => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.debit_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'lines.*.credit_account' => ['nullable', 'string', $this->tenantAccountExists()],
        ];
    }
}
