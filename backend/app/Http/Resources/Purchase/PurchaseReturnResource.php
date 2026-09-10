<?php

namespace App\Http\Resources\Purchase;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
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
            'branch_id' => $this->branch_id,
            'supplier_id' => $this->supplier_id,
            'supplier_code' => $this->supplier?->code,
            'supplier_name' => $this->supplier_name ?? $this->supplier?->name,
            'supplier_address' => $this->supplier_address ?? $this->supplier?->address,
            'tax_code' => $this->tax_code ?? $this->supplier?->tax_code,
            'deliverer_name' => $this->deliverer_name,
            'receiver_name' => $this->receiver_name,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->name,
            'voucher_type' => $this->voucher_type ?? 'purchase_return',
            'payment_method' => $this->payment_method ?? 'reduce_payable',
            'bank_account_id' => $this->bank_account_id,
            'voucher_number' => $this->voucher_number,
            'voucher_date' => $this->voucher_date?->format('Y-m-d') ?? (string) $this->voucher_date,
            'accounting_date' => $this->accounting_date?->format('Y-m-d') ?? (string) $this->accounting_date,
            'reason' => $this->reason,
            'description' => $this->description,
            'attached_docs' => $this->attached_docs,
            'sub_total' => (float) $this->sub_total,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'vat_amount' => (float) $this->tax_amount,
            'total_amount' => (float) ($this->total_amount ?: $this->grand_total),
            'grand_total' => (float) ($this->grand_total ?: $this->total_amount),
            'is_posted' => (bool) $this->is_posted,
            'is_outward' => (bool) ($this->is_outward ?? $this->is_export_slip ?? true),
            'is_export_slip' => (bool) ($this->is_export_slip ?? $this->is_outward ?? true),
            'is_decrease_debt' => (bool) $this->is_decrease_debt,
            'status' => $this->status ?? ($this->is_posted ? 'posted' : 'draft'),
            'reference_invoice_id' => $this->reference_invoice_id,
            'journal_entry_id' => $this->journal_entry_id,
            'referenced_vouchers' => $this->referenced_vouchers,
            'supplier' => $this->whenLoaded('supplier'),
            'employee' => $this->whenLoaded('employee'),
            'bank_account' => $this->whenLoaded('bankAccount'),
            'reference_invoice' => $this->whenLoaded('referenceInvoice'),
            'journal_entry' => $this->whenLoaded('journalEntry'),
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'purchase_return_id' => $line->purchase_return_id,
                        'line_order' => $line->line_order,
                        'item_id' => $line->item_id,
                        'item_code' => $line->item_code ?? $line->item?->code,
                        'item_name' => $line->item_name ?? $line->item?->name ?? $line->description,
                        'description' => $line->description ?? $line->item_name ?? $line->item?->name,
                        'unit' => $line->unit ?? (is_object($line->item?->unit) ? ($line->item->unit->name ?? (string) $line->item->unit) : $line->item?->unit),
                        'warehouse_id' => $line->warehouse_id,
                        'warehouse_code' => $line->warehouse_code ?? $line->warehouse?->code,
                        'warehouse_name' => $line->warehouse?->name,
                        // A resource must preserve missing source evidence; it
                        // must not present historical literals as mappings.
                        'debit_account' => $line->debit_account,
                        'credit_account' => $line->credit_account,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'amount' => (float) $line->amount,
                        'discount_rate' => (float) $line->discount_rate,
                        'discount_amount' => (float) $line->discount_amount,
                        'tax_rate' => (float) $line->tax_rate,
                        'tax_amount' => (float) $line->tax_amount,
                        'tax_account' => $line->tax_account,
                        'invoice_number' => $line->invoice_number,
                        'invoice_date' => $line->invoice_date?->format('Y-m-d') ?? (string) $line->invoice_date,
                        'order_id' => $line->order_id,
                        'contract_id' => $line->contract_id,
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
