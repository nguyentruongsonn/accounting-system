<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'company_id' => 'nullable|integer',
            'supplier_id' => 'required|integer',
            'supplier_name' => 'nullable|string',
            'supplier_address' => 'nullable|string',
            'deliverer_name' => 'nullable|string',
            'attached_docs' => 'nullable',
            'currency' => 'nullable|string',
            'exchange_rate' => 'nullable|numeric',
            'functional_currency_code' => 'nullable|string|size:3',
            'functional_total_amount_raw' => 'nullable|string|max:80',
            'functional_total_amount_scale' => 'nullable|integer|min:0|max:12',
            'original_total_amount_raw' => 'nullable|string|max:80',
            'original_total_amount_scale' => 'nullable|integer|min:0|max:12',
            'invoice_number' => 'required|string',
            'invoice_date' => 'required|date',
            'accounting_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'discount_amount' => 'nullable|numeric',
            'sub_total' => 'nullable|numeric',
            'tax_amount' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric',
            'referenced_vouchers' => 'nullable|array',
            'purchase_expense' => 'nullable|numeric',
            'total_stock_value' => 'nullable|numeric',
            'voucher_type' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'invoice_symbol' => 'nullable|string',
            'invoice_code' => 'nullable|string',
            'description' => 'nullable|string',
            'payment_status' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'nullable',
            'lines.*.description' => 'nullable|string',
            'lines.*.debit_account' => 'nullable|string',
            'lines.*.credit_account' => 'nullable|string',
            'lines.*.quantity' => 'required|numeric',
            'lines.*.unit_price' => 'required|numeric',
            'lines.*.discount_rate' => 'nullable|numeric',
            'lines.*.discount_amount' => 'nullable|numeric',
            'lines.*.tax_rate' => 'nullable',
            'lines.*.tax_amount' => 'nullable|numeric',
            'lines.*.tax_account' => 'nullable|string',
            'lines.*.purchase_expense' => 'nullable|numeric',
            'lines.*.stock_value' => 'nullable|numeric',
            'lines.*.unit' => 'nullable|string',
            'lines.*.warehouse' => 'nullable|string',
            'lines.*.vat_group' => 'nullable|string',
            'lines.*.import_tax_rate' => 'nullable|numeric',
            'lines.*.import_tax_amount' => 'nullable|numeric',
            'lines.*.invoice_symbol' => 'nullable|string',
            'lines.*.invoice_number' => 'nullable|string',
            'lines.*.invoice_date' => 'nullable|date',
        ];
    }
}
