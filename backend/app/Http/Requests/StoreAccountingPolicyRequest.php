<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountingPolicyRequest extends FormRequest
{
    /** @var list<string> */
    public const SUPPORTED_POLICY_KEYS = [
        'posting.cash_receipt',
        'posting.cash_payment',
        'posting.bank_receipt',
        'posting.bank_payment',
        'posting.purchase_invoice',
        'posting.sales_invoice',
        'posting.inventory_receipt',
        'posting.inventory_issue',
        'posting.purchase_return',
        'posting.purchase_discount',
        'posting.sales_return',
        'posting.sales_discount',
        'posting.period_closing',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'accounting_regime_profile_id' => ['required', 'integer'],
            'policy_key' => ['required', 'string', Rule::in(self::SUPPORTED_POLICY_KEYS)],
            'policy_version' => ['required', 'string', 'max:80'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['required', 'date', 'after_or_equal:effective_from'],
            'source_accounts' => [Rule::requiredIf(fn (): bool => $this->input('policy_key') === 'posting.period_closing'), 'array', 'min:1'],
            'source_accounts.*.account_code' => ['required_with:source_accounts', 'string', 'max:30'],
            'source_accounts.*.category' => ['required_with:source_accounts', Rule::in(['revenue', 'expense'])],
            'regulatory_dependencies' => ['present', 'array'],
            'regulatory_dependencies.*' => ['string', 'max:500'],
            'posting_rule_contract' => ['prohibited'],
            'required_dimensions' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'contract_hash' => ['prohibited'],
        ];
    }
}
