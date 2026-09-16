<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    protected $fillable = [
        'sales_invoice_id',
        'item_id',
        'description',
        'unit',
        'warehouse_id',
        'warehouse_code',
        'debit_account',
        'credit_account',
        'inventory_account',
        'cogs_account',
        'cogs_debit_account',
        'cogs_credit_account',
        'cogs_price',
        'cogs_unit_price',
        'cogs_amount',
        'quantity',
        'unit_price',
        'amount',
        'discount_rate',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'tax_account',
        'vat_group',
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
        'cogs_price' => 'decimal:2',
        'cogs_unit_price' => 'decimal:2',
        'cogs_amount' => 'decimal:2',
    ];

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
