<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'voucher_type' => $this->voucher_type,
            'contact_type' => $this->contact_type,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->contact_name,
            'bank_account_id' => $this->bank_account_id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee_name,
            'voucher_number' => $this->voucher_number,
            'voucher_date' => $this->voucher_date,
            'posting_date' => $this->posting_date,
            'payee_name' => $this->payee_name,
            'payee_address' => $this->payee_address,
            'payee_bank_account' => $this->payee_bank_account,
            'payee_bank_name' => $this->payee_bank_name,
            'payee_branch' => $this->payee_branch,
            'fee_bearer' => $this->fee_bearer ?? 'buyer',
            'description' => $this->description,
            'attached_docs' => $this->attached_docs,
            'currency' => $this->currency ?? 'VND',
            'exchange_rate' => (float) ($this->exchange_rate ?? 1),
            'amount' => (float) $this->amount,
            'status' => $this->status ?? ($this->is_posted ? 'posted' : 'draft'),
            'is_posted' => (bool) $this->is_posted,
            'journal_entry_id' => $this->journal_entry_id,
            'referenced_vouchers' => $this->referenced_vouchers,
            'references' => $this->whenLoaded('references'),
            'referenced_by' => $this->whenLoaded('referencedBy'),
            'bank_account' => $this->whenLoaded('bankAccount'),
            'employee' => $this->whenLoaded('employee'),
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'bank_payment_id' => $line->bank_payment_id,
                        'description' => $line->description,
                        'debit_account' => $line->debit_account,
                        'credit_account' => $line->credit_account,
                        'amount' => (float) $line->amount,
                        'invoice_id' => $line->invoice_id,
                        'operation' => $line->operation,
                        'loan_contract' => $line->loan_contract,
                        'line_contact_id' => $line->line_contact_id,
                        'line_contact_name' => $line->line_contact_name,
                        'bank_account_id' => $line->bank_account_id,
                        'created_at' => $line->created_at?->toISOString(),
                        'updated_at' => $line->updated_at?->toISOString(),
                    ];
                });
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
