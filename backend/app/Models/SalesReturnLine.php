<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    protected $table = 'sales_return_lines';

    protected $fillable = [
        'sales_return_id',
        'line_order',
        'item_id',
        'item_code',
        'item_name',
        'description',
        'unit',
        'warehouse_id',
        'debit_account',
        'credit_account',
        'quantity',
        'unit_price',
        'amount',
        'tax_rate',
        'tax_amount',
        'tax_account',
        'inventory_account',
        'cogs_account',
        'cogs_debit_account',
        'cogs_credit_account',
        'cogs_price',
        'cogs_unit_price',
        'cogs_amount',
        'invoice_number',
        'invoice_date',
        'sales_order_id',
        'contract_id',
    ];

    protected $casts = [
        'line_order' => 'integer',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'cogs_price' => 'decimal:2',
        'cogs_unit_price' => 'decimal:2',
        'cogs_amount' => 'decimal:2',
        'invoice_date' => 'date',
    ];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
