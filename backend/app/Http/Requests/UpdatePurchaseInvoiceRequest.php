<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'company_id' => 'nullable|integer',
            'supplier_id' => 'sometimes|required|integer',
            'supplier_name' => 'nullable|string',
            'supplier_address' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'invoice_address' => 'nullable|string|max:500',
            'tax_code' => 'nullable|string|max:50',
            'employee_id' => 'nullable|integer',
            'employee_name' => 'nullable|string',
            'deliverer_name' => 'nullable|string',
            'receiver_name' => 'nullable|string|max:255',
            'receiver_address' => 'nullable|string|max:500',
            'attached_docs' => 'nullable',
            'currency' => 'nullable|string',
            'exchange_rate' => 'nullable|numeric',
            'functional_currency_code' => 'nullable|string|size:3',
            'functional_total_amount_raw' => 'nullable|string|max:80',
            'functional_total_amount_scale' => 'nullable|integer|min:0|max:12',
            'original_total_amount_raw' => 'nullable|string|max:80',
            'original_total_amount_scale' => 'nullable|integer|min:0|max:12',
            'invoice_number' => 'sometimes|required|string',
            'invoice_date' => 'sometimes|required|date',
            'accounting_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'discount_amount' => 'nullable|numeric',
            'sub_total' => 'nullable|numeric',
            'tax_amount' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric',
            'referenced_vouchers' => 'nullable|array',
            'expense_allocations' => 'nullable|array',
            'expense_allocations.*.target_purchase_invoice_id' => 'required|integer|min:1',
            'expense_allocations.*.target_purchase_invoice_line_id' => 'nullable|integer|min:1',
            'expense_allocations.*.allocated_amount' => 'required|numeric|gt:0',
            'expense_allocations.*.allocation_method' => 'required|in:value,quantity,manual',
            'purchase_expense' => 'nullable|numeric',
            'total_stock_value' => 'nullable|numeric',
            'voucher_type' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'payment_status' => 'nullable|string',
            'payment_term_code' => 'nullable|string|max:50',
            'due_days' => 'nullable|integer|min:0',
            'spend_reason' => 'nullable|string|max:500',
            'payment_slip_number' => 'nullable|string|max:50',
            'is_purchase_expense' => 'nullable|boolean',
            'is_include_invoice' => 'nullable|boolean',
            'invoice_option' => 'nullable|string|max:100',
            'invoice_symbol' => 'nullable|string',
            'invoice_code' => 'nullable|string',
            'invoice_form' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*.item_id' => 'nullable',
            'lines.*.service_code' => 'nullable|string|max:50',
            'lines.*.description' => 'nullable|string',
            'lines.*.debit_account' => 'nullable|string',
            'lines.*.credit_account' => 'nullable|string',
            'lines.*.quantity' => 'required_with:lines|numeric',
            'lines.*.unit_price' => 'required_with:lines|numeric',
            'lines.*.discount_rate' => 'nullable|numeric',
            'lines.*.discount_amount' => 'nullable|numeric',
            'lines.*.tax_rate' => 'nullable',
            'lines.*.tax_amount' => 'nullable|numeric',
            'lines.*.tax_account' => 'nullable|string',
            'lines.*.purchase_expense' => 'nullable|numeric',
            'lines.*.stock_value' => 'nullable|numeric',
            'lines.*.unit' => 'nullable|string',
            'lines.*.warehouse' => 'nullable|string',
            'lines.*.warehouse_id' => 'nullable|integer',
            'lines.*.warehouse_code' => 'nullable|string|max:50',
            'lines.*.order_id' => 'nullable|integer',
            'lines.*.contract_id' => 'nullable|integer',
            'lines.*.vat_group' => 'nullable|string',
            'lines.*.import_tax_rate' => 'nullable|numeric',
            'lines.*.import_tax_amount' => 'nullable|numeric',
            'lines.*.invoice_symbol' => 'nullable|string',
            'lines.*.invoice_number' => 'nullable|string',
            'lines.*.invoice_date' => 'nullable|date',
            'lines.*.cost_item_code' => 'nullable|string|max:50',
            'lines.*.cost_object_code' => 'nullable|string|max:50',
        ];
    }
}
