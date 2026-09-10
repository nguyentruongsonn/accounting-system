<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashReceiptResource extends JsonResource
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
            'voucher_number' => $this->voucher_number,
            'voucher_date' => $this->voucher_date,
            'posting_date' => $this->posting_date,
            'payer_name' => $this->payer_name,
            'payer_address' => $this->payer_address,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee_name,
            'reason' => $this->reason ?? $this->description,
            'description' => $this->description ?? $this->reason,
            'referenced_vouchers' => $this->referenced_vouchers ?? [],
            'references' => $this->whenLoaded('references'),
            'referenced_by' => $this->whenLoaded('referencedBy'),
            'attached_docs' => $this->attached_docs,
            'currency' => $this->currency ?? 'VND',
            'exchange_rate' => (float) ($this->exchange_rate ?? 1),
            'total_amount' => (float) $this->total_amount,
            'status' => (bool) $this->is_posted ? 'posted' : ($this->status ?? 'draft'),
            'is_posted' => (bool) $this->is_posted,
            'journal_entry_id' => $this->journal_entry_id,
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'cash_receipt_id' => $line->cash_receipt_id,
                        'debit_account' => $line->debit_account,
                        'credit_account' => $line->credit_account,
                        'description' => $line->description,
                        'operation' => $line->operation,
                        'amount' => (float) $line->amount,
                        'loan_contract' => $line->loan_contract,
                        'line_contact_id' => $line->line_contact_id,
                        'line_contact_name' => $line->line_contact_name,
                        'sub_object_type' => $line->sub_object_type,
                        'sub_object_id' => $line->sub_object_id,
                        'invoice_id' => $line->invoice_id,
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
