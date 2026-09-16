<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFixedAssetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()?->company_id !== null) {
            $this->merge(['company_id' => (int) $this->user()->company_id]);
        }
    }

    public function rules(): array
    {
        $companyId = (int) $this->user()?->company_id;
        $companyAccount = static fn () => Rule::exists('chart_of_accounts', 'code')
            ->where(static fn ($query) => $query
                ->where('company_id', $companyId)
                ->whereNull('deleted_at'));

        return [
            'company_id' => ['required', 'integer', Rule::in([$companyId])],
            'voucher_number' => 'nullable|string|max:50',
            'voucher_date' => 'nullable|date',
            'asset_code' => 'nullable|string|max:100',
            'asset_name' => 'required|string|max:255',
            'category_code' => 'nullable|string|max:100',
            'department_code' => 'nullable|string|max:100',
            'quantity' => 'nullable|integer|min:1',
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where(
                    static fn ($query) => $query->where('company_id', $companyId)
                ),
            ],
            'supplier_name' => 'nullable|string|max:255',
            'purchase_date' => 'required|date',
            'start_depreciation_date' => 'nullable|date',
            'original_cost' => 'required|numeric|min:0',
            'depreciable_cost' => 'nullable|numeric|min:0',
            'useful_life_months' => 'nullable|integer|min:0',
            'accumulated_depreciation' => 'nullable|numeric|min:0',
            'asset_account' => ['nullable', 'string', 'max:20', $companyAccount()],
            'depreciation_account' => ['nullable', 'string', 'max:20', $companyAccount()],
            'expense_account' => ['nullable', 'string', 'max:20', $companyAccount()],
            'credit_account' => ['nullable', 'string', 'max:20', $companyAccount()],
            'is_posted' => 'nullable|boolean',
            'status' => 'nullable|string|max:30',
            'referenced_vouchers' => 'nullable|array',
        ];
    }
}
