<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceivedInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'supplier_tax_code' => $this->supplier?->tax_code,
            'invoice_template' => $this->invoice_template,
            'invoice_series' => $this->invoice_series,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'subtotal' => (string) $this->subtotal,
            'vat_rate' => (string) $this->vat_rate,
            'vat_amount' => (string) $this->vat_amount,
            'total_amount' => (string) $this->total_amount,
            'status' => $this->status,
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_contract_id' => $this->purchase_contract_id,
            'inventory_receipt_id' => $this->inventory_receipt_id,
            'voucher_ref' => $this->voucher_ref,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
