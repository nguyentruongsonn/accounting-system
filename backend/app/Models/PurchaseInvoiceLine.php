<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'item_id',
        'description',
        'debit_account',
        'credit_account',
        'quantity',
        'unit_price',
        'amount',
        'discount_rate',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'tax_account',
        'purchase_expense',
        'stock_value',
        'unit',
        'warehouse',
        'warehouse_id',
        'warehouse_code',
        'vat_group',
        'import_tax_rate',
        'import_tax_amount',
        'invoice_symbol',
        'invoice_number',
        'invoice_date',
        'order_id',
        'contract_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'purchase_expense' => 'decimal:2',
        'stock_value' => 'decimal:2',
        'invoice_date' => 'date',
    ];

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
