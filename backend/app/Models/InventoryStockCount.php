<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryStockCount extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'count_number', 'count_date', 'warehouse_id', 'description',
        'status', 'is_posted', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'count_date' => 'date:Y-m-d',
        'is_posted' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryStockCountLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
