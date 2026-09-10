<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
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
            'fiscal_year_id' => $this->fiscal_year_id,
            'voucher_type' => $this->voucher_type,
            'voucher_number' => $this->voucher_number,
            'voucher_date' => $this->voucher_date ? (is_string($this->voucher_date) ? substr($this->voucher_date, 0, 10) : $this->voucher_date->format('Y-m-d')) : null,
            'posting_date' => $this->posting_date ? (is_string($this->posting_date) ? substr($this->posting_date, 0, 10) : $this->posting_date->format('Y-m-d')) : null,
            'description' => $this->description,
            'reason' => $this->description,
            'total_amount' => (float) $this->total_amount,
            'total_amount_decimal' => (string) $this->total_amount,
            'status' => $this->status,
            'is_posted' => $this->status === 'posted',
            'currency' => $this->currency ?? 'VND',
            'exchange_rate' => (float) ($this->exchange_rate ?? 1),
            'attached_docs' => $this->attached_docs,
            'referenced_vouchers' => $this->referenced_vouchers,
            'references' => $this->whenLoaded('references'),
            'source_document_type' => $this->source_document_type,
            'source_document_id' => $this->source_document_id,
            'reversal_of_id' => $this->reversal_of_id,
            'reversed_by_entry_id' => $this->reversed_by_entry_id,
            'reversal_reason' => $this->reversal_reason,
            'reversed_by' => $this->reversed_by,
            'reversed_at' => $this->reversed_at?->toISOString(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    $amount = $line->debit_amount !== '0.00'
                        ? $line->debit_amount
                        : $line->credit_amount;

                    return [
                        'id' => $line->id,
                        'journal_entry_id' => $line->journal_entry_id,
                        'account_code' => $line->account_code,
                        'description' => $line->description,
                        'debit_amount' => (float) $line->debit_amount,
                        'credit_amount' => (float) $line->credit_amount,
                        'amount' => (float) $amount,
                        'debit_amount_decimal' => (string) $line->debit_amount,
                        'credit_amount_decimal' => (string) $line->credit_amount,
                        'amount_decimal' => (string) $amount,
                        'contact_type' => $line->contact_type,
                        'contact_id' => $line->contact_id,
                        'contact_name' => $line->contact_name,
                        'cost_item_code' => $line->cost_item_code,
                        'cost_object_code' => $line->cost_object_code,
                        'bank_account_id' => $line->bank_account_id,
                        'sub_object_type' => $line->sub_object_type,
                        'sub_object_id' => $line->sub_object_id,
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
