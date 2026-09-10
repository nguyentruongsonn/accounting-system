<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceResource extends JsonResource
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
            'voucher_number' => $this->voucher_number ?? $this->invoice_number,
            'invoice_number' => $this->invoice_number,
            'voucher_type' => $this->voucher_type,
            'voucher_date' => $this->voucher_date ?? $this->invoice_date,
            'invoice_date' => $this->invoice_date,
            'accounting_date' => $this->accounting_date,
            'due_date' => $this->due_date,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name ?? $this->supplier?->name,
            'supplier_address' => $this->supplier_address ?? $this->supplier?->address,
            'tax_code' => $this->tax_code ?? $this->supplier?->tax_code,
            'employee_id' => $this->employee_id,
            'description' => $this->description,
            'currency' => $this->currency ?? 'VND',
            'exchange_rate' => (float) ($this->exchange_rate ?? 1),
            'sub_total' => (float) $this->sub_total,
            'discount_amount' => (float) ($this->discount_amount ?? 0),
            'tax_amount' => (float) $this->tax_amount,
            'purchase_expense' => (float) ($this->purchase_expense ?? 0),
            'total_amount' => (float) $this->total_amount,
            // Canonical settlement allocations update payment_status through
            // ApArSettlementStatusPolicy. Do not infer a paid invoice from
            // its payment method: an unpaid invoice can be partially settled
            // and legacy payment methods are not posting evidence.
            'payment_status' => $this->payment_status
                ?? ($this->payment_method === 'unpaid' ? 'Unpaid' : 'Paid'),
            'payment_method' => $this->payment_method,
            'is_posted' => (bool) $this->is_posted,
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'item_id' => $line->item_id,
                        'service_code' => $line->service_code ?? $line->item?->code,
                        'description' => $line->description,
                        'unit' => $line->unit,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'amount' => (float) $line->amount,
                        'discount_rate' => (float) ($line->discount_rate ?? 0),
                        'discount_amount' => (float) ($line->discount_amount ?? 0),
                        'tax_rate' => (float) ($line->tax_rate ?? 0),
                        'tax_amount' => (float) ($line->tax_amount ?? 0),
                        // Preserve server evidence; a resource must not make a
                        // missing tax mapping look like an approved account.
                        'tax_account' => $line->tax_account,
                        'debit_account' => $line->debit_account,
                        'credit_account' => $line->credit_account,
                        'vat_group' => $line->vat_group,
                        'cost_item_code' => $line->cost_item_code,
                        'cost_object_code' => $line->cost_object_code,
                    ];
                });
            }),
            'supplier' => $this->whenLoaded('supplier'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
