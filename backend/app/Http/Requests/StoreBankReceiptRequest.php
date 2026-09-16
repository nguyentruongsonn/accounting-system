<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreBankReceiptRequest extends TenantAccountingRequest
{
    public function rules()
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'bank_account_id' => [$required, 'integer', $this->tenantExists('bank_accounts')],
            'voucher_number' => [$required, 'string'],
            'voucher_date' => [$required, 'date'],
            'posting_date' => 'nullable|date',
            'voucher_type' => 'nullable|string',
            'contact_type' => 'nullable|string',
            'contact_id' => ['nullable', $this->tenantContactExists()],
            'contact_name' => 'nullable|string',
            'payer_name' => 'nullable|string|max:255',
            'payer_address' => 'nullable|string|max:255',
            'payer_bank_account' => 'nullable|string|max:100',
            'employee_id' => ['nullable', $this->tenantExists('employees', 'id', true)],
            'employee_name' => 'nullable|string',
            'description' => 'nullable|string',
            'attached_docs' => 'nullable|string',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'referenced_vouchers' => 'nullable|array',
            'referenced_vouchers.*' => ['array', $this->tenantVoucherReferenceExists()],
            'lines' => [$required, 'array', 'min:1'],
            'lines.*.description' => 'nullable|string',
            'lines.*.debit_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'lines.*.credit_account' => ['required', 'string', $this->tenantAccountExists()],
            'lines.*.amount' => 'required|numeric|min:1',
            'lines.*.line_contact_id' => ['nullable', $this->tenantContactExists()],
            'lines.*.line_contact_name' => 'nullable|string',
            'lines.*.invoice_id' => ['nullable', 'integer', $this->tenantExists('sales_invoices')],
            'lines.*.bank_account_id' => ['nullable', 'integer', $this->tenantExists('bank_accounts')],
        ];
    }
}
