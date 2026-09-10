<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryReceiptResource extends JsonResource
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
            'voucher_type' => $this->voucher_type ?? '1. Nhập kho mua hàng',
            'contact_type' => $this->contact_type,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->contact_name,
            'deliverer_name' => $this->deliverer_name,
            'receiver_address' => $this->receiver_address,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee_name ?? $this->employee?->name,
            'warehouse_id' => $this->warehouse_id,
            'voucher_number' => $this->voucher_number,
            'voucher_date' => $this->voucher_date ? (is_string($this->voucher_date) ? substr($this->voucher_date, 0, 10) : $this->voucher_date->format('Y-m-d')) : null,
            'posting_date' => $this->posting_date ? (is_string($this->posting_date) ? substr($this->posting_date, 0, 10) : $this->posting_date->format('Y-m-d')) : null,
            'description' => $this->description,
            'attached_docs' => $this->attached_docs,
            'currency' => $this->currency ?? 'VND',
            'exchange_rate' => (float) ($this->exchange_rate ?? 1),
            'total_amount' => (float) $this->total_amount,
            'status' => $this->is_posted ? 'posted' : ($this->status ?? 'draft'),
            'is_posted' => (bool) $this->is_posted,
            'journal_entry_id' => $this->journal_entry_id,
            'referenced_vouchers' => $this->referenced_vouchers,
            'employee' => $this->whenLoaded('employee'),
            'references' => $this->whenLoaded('references'),
            'journal_entry' => $this->whenLoaded('journalEntry'),
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'inventory_receipt_id' => $line->inventory_receipt_id,
                        'item_id' => $line->item_id,
                        'item_code' => $line->item?->code,
                        'item_name' => $line->item?->name ?? $line->description,
                        'unit' => $line->unit ?? $line->item?->unit ?? 'Cái',
                        'warehouse_id' => $line->warehouse_id,
                        'warehouse_code' => $line->warehouse_code ?? $line->warehouse?->code,
                        'warehouse_name' => $line->warehouse?->name,
                        'description' => $line->description,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'amount' => (float) $line->amount,
                        'debit_account' => $line->debit_account,
                        'credit_account' => $line->credit_account,
                        'item' => $line->relationLoaded('item') ? $line->item : null,
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
