<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'company_id' => 'required|integer|exists:companies,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'customer_name' => 'nullable|string',
            'customer_address' => 'nullable|string',
            'receiver_name' => 'nullable|string',
            'invoice_number' => 'required|string|unique:sales_invoices',
            'invoice_date' => 'required|date',
            'accounting_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'description' => 'nullable|string',
            'attached_docs' => 'nullable|string',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string|in:Unpaid,Paid',
            'is_export_slip' => 'nullable|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'nullable|string',
            'lines.*.item_id' => 'nullable|integer|exists:items,id',
            'lines.*.debit_account' => 'required|string|exists:chart_of_accounts,code',
            'lines.*.credit_account' => 'required|string|exists:chart_of_accounts,code',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'required|numeric|min:0|max:100',
            'lines.*.tax_amount' => 'nullable|numeric|min:0',
            'lines.*.tax_account' => 'nullable|string|exists:chart_of_accounts,code',
            'lines.*.inventory_account' => 'nullable|string|exists:chart_of_accounts,code',
            'lines.*.cogs_account' => 'nullable|string|exists:chart_of_accounts,code',
            'lines.*.cogs_price' => 'nullable|numeric|min:0',
        ];
    }
}
