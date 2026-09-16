<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceResource extends JsonResource
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
            'customer_id' => $this->customer_id,
            // These are document snapshots, not live master-data fallbacks.
            // Returning the related customer's value when the snapshot was
            // explicitly cleared makes a successful edit appear not to save.
            'customer_name' => $this->customer_name,
            'customer_address' => $this->customer_address,
            'receiver_name' => $this->receiver_name,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date,
            'accounting_date' => $this->accounting_date,
            'due_date' => $this->due_date,
            'payment_terms' => $this->payment_terms,
            'due_days' => $this->due_days !== null ? (int) $this->due_days : null,
            'invoice_form_number' => $this->invoice_form_number,
            'payment_method_display' => $this->payment_method_display,
            'bank_account' => $this->bank_account,
            'budget_relation_code' => $this->budget_relation_code,
            'id_card_number' => $this->id_card_number,
            'passport_number' => $this->passport_number,
            'sub_total' => (float) $this->sub_total,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'description' => $this->description,
            'attached_docs' => $this->attached_docs,
            'currency' => $this->currency ?? 'VND',
            'exchange_rate' => (float) ($this->exchange_rate ?? 1),
            'is_export_slip' => (bool) $this->is_export_slip,
            'is_posted' => (bool) $this->is_posted,
            'journal_entry_id' => $this->journal_entry_id,
            'customer' => $this->whenLoaded('customer'),
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'sales_invoice_id' => $line->sales_invoice_id,
                        'item_id' => $line->item_id,
                        'description' => $line->description,
                        'debit_account' => $line->debit_account,
                        'credit_account' => $line->credit_account,
                        'inventory_account' => $line->inventory_account,
                        'cogs_account' => $line->cogs_account,
                        'cogs_price' => (float) ($line->cogs_price ?? 0),
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'amount' => (float) $line->amount,
                        'discount_rate' => (float) ($line->discount_rate ?? 0),
                        'discount_amount' => (float) ($line->discount_amount ?? 0),
                        'tax_rate' => (float) ($line->tax_rate ?? 0),
                        'tax_amount' => (float) ($line->tax_amount ?? 0),
                        'tax_account' => $line->tax_account,
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
