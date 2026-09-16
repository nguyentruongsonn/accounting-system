<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()?->company_id !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()?->company_id !== null) {
            $this->merge(['company_id' => (int) $this->user()->company_id]);
        }
    }

    public function rules()
    {
        $companyId = (int) $this->user()->company_id;
        $companyExists = static fn (string $table, string $column = 'id') => Rule::exists($table, $column)
            ->where(static fn ($query) => $query->where('company_id', $companyId));
        $activeCompanyAccount = static fn () => Rule::exists('chart_of_accounts', 'code')
            ->where(static fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_parent', false)
                ->whereNull('deleted_at'));

        return [
            'company_id' => ['required', 'integer', Rule::in([$companyId])],
            'fiscal_year_id' => [
                'nullable',
                'integer',
                Rule::exists('fiscal_years', 'id')->where(static fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')),
            ],
            'voucher_type' => 'nullable|string',
            'voucher_number' => 'nullable|string',
            'voucher_date' => 'required|date',
            'posting_date' => 'required|date',
            'reason' => 'nullable|string',
            'description' => 'nullable|string',
            'total_amount' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(['draft'])],
            'currency' => 'nullable|string',
            'exchange_rate' => 'nullable|numeric',
            'attached_docs' => 'nullable',
            'referenced_vouchers' => 'nullable|array',
            'lines' => 'required|array|min:1',
            'lines.*.account_code' => ['nullable', 'string', $activeCompanyAccount()],
            'lines.*.debit_account' => ['nullable', 'string', $activeCompanyAccount()],
            'lines.*.credit_account' => ['nullable', 'string', $activeCompanyAccount()],
            'lines.*.debit_amount' => 'nullable|numeric|min:0',
            'lines.*.credit_amount' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.description' => 'nullable|string',
            'lines.*.contact_type' => 'nullable|string',
            'lines.*.contact_id' => 'nullable',
            'lines.*.contact_name' => 'nullable|string',
            'lines.*.cost_item_code' => 'nullable|string',
            'lines.*.cost_object_code' => 'nullable|string',
            'lines.*.bank_account_id' => ['nullable', 'integer', $companyExists('bank_accounts')],
            'lines.*.sub_object_type' => 'nullable|string',
            'lines.*.sub_object_id' => 'nullable|integer',
        ];
    }
}
