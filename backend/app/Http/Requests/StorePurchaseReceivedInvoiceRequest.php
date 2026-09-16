<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReceivedInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = TenantContext::companyId($this);

        return [
            'supplier_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)),
            ],
            'invoice_template' => ['nullable', 'string', 'max:50'],
            'invoice_series' => ['required', 'string', 'max:50'],
            'invoice_number' => ['required', 'string', 'max:50'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'purchase_invoice_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'purchase_order_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'purchase_contract_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('purchase_contracts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'inventory_receipt_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('inventory_receipts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ];
    }
}
