<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'customer_id' => 'required',
            'customer_name' => 'nullable|string',
            'customer_address' => 'nullable|string',
            'tax_code' => 'nullable|string|max:50',
            'receiver_name' => 'nullable|string|max:255',
            'employee_id' => 'nullable',
            'voucher_type' => 'nullable|string|max:100',
            'payment_method' => 'nullable|string|in:reduce_receivable,cash,bank',
            'bank_account_id' => 'nullable',
            'voucher_number' => 'nullable|string|max:50|unique:sales_discounts,voucher_number',
            'voucher_date' => 'required|date',
            'accounting_date' => 'nullable|date',
            'reason' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'attached_docs' => 'nullable',
            'sub_total' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'grand_total' => 'nullable|numeric|min:0',
            'is_decrease_debt' => 'nullable|boolean',
            'is_posted' => 'nullable|boolean',
            'status' => 'nullable|string|max:30',
            'reference_invoice_id' => 'nullable',
            'referenced_vouchers' => 'nullable|array',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'nullable',
            'lines.*.item_code' => 'nullable|string|max:50',
            'lines.*.item_name' => 'nullable|string|max:255',
            'lines.*.description' => 'nullable|string',
            'lines.*.unit' => 'nullable|string|max:50',
            'lines.*.debit_account' => 'nullable|string|max:20',
            'lines.*.credit_account' => 'nullable|string|max:20',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.tax_amount' => 'nullable|numeric|min:0',
            'lines.*.tax_account' => 'nullable|string|max:20',
            'lines.*.invoice_number' => 'nullable|string|max:50',
            'lines.*.invoice_date' => 'nullable|date',
            'lines.*.sales_order_id' => 'nullable',
            'lines.*.contract_id' => 'nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Vui lòng chọn khách hàng.',
            'voucher_date.required' => 'Vui lòng nhập ngày chứng từ.',
            'lines.required' => 'Chứng từ phải có ít nhất 1 dòng chi tiết.',
            'lines.min' => 'Chứng từ phải có ít nhất 1 dòng chi tiết.',
        ];
    }
}
