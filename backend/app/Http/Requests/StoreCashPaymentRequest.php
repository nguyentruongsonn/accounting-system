<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreCashPaymentRequest extends TenantAccountingRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // `receiver_*` is the canonical cash-voucher contract.  Accept the
        // legacy `payee_*` spelling at the boundary so older MISA imports do
        // not silently lose the recipient while keeping one stored shape.
        $aliases = [];
        if (! $this->has('receiver_name') && $this->filled('payee_name')) {
            $aliases['receiver_name'] = $this->input('payee_name');
        }
        if (! $this->has('receiver_address') && $this->filled('payee_address')) {
            $aliases['receiver_address'] = $this->input('payee_address');
        }
        if (! $this->has('reason') && $this->filled('description')) {
            $aliases['reason'] = $this->input('description');
        }
        if ($aliases !== []) {
            $this->merge($aliases);
        }
    }

    public function rules()
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'voucher_type' => 'nullable|string|max:150',
            'voucher_number' => [$required, 'string'],
            'voucher_date' => [$required, 'date'],
            'posting_date' => [$required, 'date'],
            'contact_type' => 'nullable|string',
            'contact_id' => ['nullable', $this->tenantContactExists()],
            'contact_name' => 'nullable|string',
            'receiver_name' => 'nullable|string|max:255',
            'receiver_address' => 'nullable|string',
            'employee_id' => ['nullable', $this->tenantExists('employees', 'id', true)],
            'employee_name' => 'nullable|string',
            'reason' => 'nullable|string',
            'description' => 'nullable|string',
            'referenced_vouchers' => 'nullable|array',
            'referenced_vouchers.*' => ['array', $this->tenantVoucherReferenceExists()],
            'attached_docs' => 'nullable|string',
            'currency' => 'nullable|string|max:10',
            'exchange_rate' => 'nullable|numeric|min:0',
            'lines' => [$required, 'array', 'min:1'],
            'lines.*.description' => 'nullable|string',
            'lines.*.debit_account' => ['required', 'string', 'max:20', $this->tenantAccountExists()],
            'lines.*.credit_account' => ['nullable', 'string', 'max:20', $this->tenantAccountExists()],
            'lines.*.amount' => 'required|numeric|min:0',
            'lines.*.operation' => 'nullable|string',
            'lines.*.loan_contract' => 'nullable|string',
            'lines.*.line_contact_id' => ['nullable', $this->tenantContactExists()],
            'lines.*.line_contact_name' => 'nullable|string',
            'lines.*.invoice_id' => ['nullable', 'integer', $this->tenantExists('purchase_invoices')],
        ];
    }
}
