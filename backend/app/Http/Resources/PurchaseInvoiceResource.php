<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\DecimalMoney;

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
            'due_days' => $this->due_days !== null ? (int) $this->due_days : null,
            'payment_term_code' => $this->payment_term_code,
            'spend_reason' => $this->spend_reason,
            'supplier_id' => $this->supplier_id,
            // These are snapshots stored on the purchase document. Do not
            // replace an explicit null with live supplier master data after
            // the user clears the field.
            'supplier_name' => $this->supplier_name,
            'supplier_address' => $this->supplier_address,
            'contact_name' => $this->contact_name,
            'invoice_address' => $this->invoice_address ?? $this->supplier_address ?? $this->supplier?->address,
            'deliverer_name' => $this->deliverer_name,
            'receiver_name' => $this->receiver_name ?? $this->deliverer_name,
            'receiver_address' => $this->receiver_address,
            'tax_code' => $this->tax_code ?? $this->supplier?->tax_code,
            'payment_slip_number' => $this->payment_slip_number,
            'invoice_option' => $this->invoice_option,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee_name ?? $this->employee?->name,
            'invoice_symbol' => $this->invoice_symbol,
            'invoice_code' => $this->invoice_code,
            'invoice_form' => $this->invoice_form ?? $this->invoice_code,
            'description' => $this->description,
            'attached_docs' => $this->attached_docs !== null ? (string) $this->attached_docs : null,
            'currency' => $this->currency ?? 'VND',
            'exchange_rate' => (float) ($this->exchange_rate ?? 1),
            'sub_total' => (float) $this->sub_total,
            'discount_amount' => (float) ($this->discount_amount ?? 0),
            'tax_amount' => (float) $this->tax_amount,
            'purchase_expense' => (float) ($this->purchase_expense ?? 0),
            'total_stock_value' => (float) ($this->total_stock_value ?? 0),
            'total_amount' => (float) $this->total_amount,
            'payment_status' => $this->payment_status
                ?? ($this->payment_method === 'unpaid' ? 'Unpaid' : 'Paid'),
            'payment_method' => $this->payment_method,
            'status' => $this->status ?? ($this->is_posted ? 'posted' : 'draft'),
            'is_posted' => (bool) $this->is_posted,
            'is_purchase_expense' => (bool) ($this->is_purchase_expense ?? false),
            'is_include_invoice' => $this->is_include_invoice !== null ? (bool) $this->is_include_invoice : true,
            'referenced_vouchers' => $this->referenced_vouchers,
            'expense_allocations' => $this->whenLoaded('expenseAllocations', function () {
                return $this->expenseAllocations->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'target_purchase_invoice_id' => $allocation->target_purchase_invoice_id,
                    'target_purchase_invoice_line_id' => $allocation->target_purchase_invoice_line_id,
                    'allocated_amount' => DecimalMoney::normalize($allocation->getRawOriginal('allocated_amount')),
                    'allocation_method' => $allocation->allocation_method,
                    'effective_date' => $allocation->effective_date?->format('Y-m-d'),
                ])->values();
            }),
            'received_expense_allocations' => $this->whenLoaded('receivedExpenseAllocations', function () {
                return $this->receivedExpenseAllocations->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'source_invoice_id' => $allocation->source_purchase_invoice_id,
                    'source_voucher_number' => $allocation->sourceInvoice?->invoice_number
                        ?? $allocation->sourceInvoice?->voucher_number,
                    'source_accounting_date' => $allocation->sourceInvoice?->accounting_date?->format('Y-m-d'),
                    'source_voucher_date' => $allocation->sourceInvoice?->invoice_date?->format('Y-m-d'),
                    'source_supplier_name' => $allocation->sourceInvoice?->supplier_name
                        ?? $allocation->sourceInvoice?->supplier?->name,
                    'source_description' => $allocation->sourceInvoice?->description,
                    'source_total_expense' => $allocation->sourceInvoice
                        ? (float) ($allocation->sourceInvoice->purchase_expense ?? 0)
                        : 0,
                    'allocated_amount' => DecimalMoney::normalize($allocation->getRawOriginal('allocated_amount')),
                    'allocation_method' => $allocation->allocation_method,
                    'effective_date' => $allocation->effective_date?->format('Y-m-d'),
                ])->values();
            }),
            'allocated_total' => $this->whenLoaded('expenseAllocations', fn () => (float) $this->expenseAllocations->sum(
                fn ($allocation): float => (float) $allocation->getRawOriginal('allocated_amount'),
            )),
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
                        'tax_account' => $line->tax_account,
                        'debit_account' => $line->debit_account,
                        'credit_account' => $line->credit_account,
                        'vat_group' => $line->vat_group,
                        'purchase_expense' => (float) ($line->purchase_expense ?? 0),
                        'stock_value' => (float) ($line->stock_value ?? 0),
                        'warehouse' => $line->warehouse,
                        'warehouse_id' => $line->warehouse_id,
                        'warehouse_code' => $line->warehouse_code,
                        'import_tax_rate' => (float) ($line->import_tax_rate ?? 0),
                        'import_tax_amount' => (float) ($line->import_tax_amount ?? 0),
                        'invoice_symbol' => $line->invoice_symbol,
                        'invoice_number' => $line->invoice_number,
                        'invoice_date' => $line->invoice_date instanceof \DateTimeInterface
                            ? $line->invoice_date->format('Y-m-d')
                            : $line->invoice_date,
                        'order_id' => $line->order_id,
                        'contract_id' => $line->contract_id,
                        'cost_item_code' => $line->cost_item_code,
                        'cost_object_code' => $line->cost_object_code,
                    ];
                });
            }),
            'supplier' => $this->whenLoaded('supplier'),
            'employee' => $this->whenLoaded('employee'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
