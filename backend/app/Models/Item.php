<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'unit_conversions' => 'array',
        'tier_discounts' => 'array',
        'combo_details' => 'array',
        'custom_fields' => 'array',
        'is_active' => 'boolean',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'fixed_purchase_price' => 'decimal:2',
        'latest_purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'minimum_stock' => 'decimal:2',
        'purchase_discount_rate' => 'decimal:2',
        'import_tax_rate' => 'decimal:2',
        'export_tax_rate' => 'decimal:2',
    ];

    public function setPurchasePriceAttribute($value): void
    {
        $this->attributes['cost_price'] = $value;
        $this->attributes['fixed_purchase_price'] = $value;
        $this->attributes['latest_purchase_price'] = $value;
    }

    public function getPurchasePriceAttribute()
    {
        return $this->attributes['purchase_price'] ?? $this->attributes['cost_price'] ?? $this->attributes['fixed_purchase_price'] ?? $this->attributes['latest_purchase_price'] ?? 0;
    }

    public function setSellingPriceAttribute($value): void
    {
        $this->attributes['selling_price'] = $value;
        $this->attributes['sale_price'] = $value;
    }
}
