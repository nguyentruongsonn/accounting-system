<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesDiscountResource extends JsonResource
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
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name ?? $this->customer?->name,
            'customer_address' => $this->customer_address ?? $this->customer?->address,
            'tax_code' => $this->tax_code ?? $this->customer?->tax_code,
            'receiver_name' => $this->receiver_name,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->name,
            'voucher_type' => $this->voucher_type ?? 'sales_discount',
            'payment_method' => $this->payment_method ?? 'reduce_receivable',
            'bank_account_id' => $this->bank_account_id,
            'voucher_number' => $this->voucher_number,
            'voucher_date' => $this->voucher_date?->format('Y-m-d') ?? (string) $this->voucher_date,
            'accounting_date' => $this->accounting_date?->format('Y-m-d') ?? (string) $this->accounting_date,
            'reason' => $this->reason,
            'description' => $this->description,
            'attached_docs' => $this->attached_docs,
            'sub_total' => (float) $this->sub_total,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) ($this->total_amount ?: $this->grand_total),
            'grand_total' => (float) ($this->grand_total ?: $this->total_amount),
            'is_posted' => (bool) $this->is_posted,
            'is_decrease_debt' => (bool) $this->is_decrease_debt,
            'status' => $this->status ?? ($this->is_posted ? 'posted' : 'draft'),
            'reference_invoice_id' => $this->reference_invoice_id,
            'journal_entry_id' => $this->journal_entry_id,
            'referenced_vouchers' => $this->referenced_vouchers,
            'customer' => $this->whenLoaded('customer'),
            'employee' => $this->whenLoaded('employee'),
            'bank_account' => $this->whenLoaded('bankAccount'),
            'reference_invoice' => $this->whenLoaded('referenceInvoice'),
            'journal_entry' => $this->whenLoaded('journalEntry'),
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'sales_discount_id' => $line->sales_discount_id,
                        'line_order' => $line->line_order,
                        'item_id' => $line->item_id,
                        'item_code' => $line->item_code ?? $line->item?->code,
                        'item_name' => $line->item_name ?? $line->item?->name ?? $line->description,
                        'description' => $line->description ?? $line->item_name ?? $line->item?->name,
                        'unit' => $line->unit ?? (is_object($line->item?->unit) ? ($line->item->unit->name ?? (string) $line->item->unit) : $line->item?->unit),
                        'debit_account' => $line->debit_account ?? '5213',
                        'credit_account' => $line->credit_account ?? '131',
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'amount' => (float) ($line->amount ?: $line->discount_amount),
                        'discount_amount' => (float) ($line->discount_amount ?: $line->amount),
                        'tax_rate' => (float) $line->tax_rate,
                        'tax_amount' => (float) $line->tax_amount,
                        'tax_account' => $line->tax_account ?? '33311',
                        'invoice_number' => $line->invoice_number,
                        'invoice_date' => $line->invoice_date?->format('Y-m-d') ?? (string) $line->invoice_date,
                        'sales_order_id' => $line->sales_order_id,
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
