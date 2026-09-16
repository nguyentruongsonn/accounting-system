<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashForecastItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_forecast_id',
        'code',
        'name',
        'amount',
        'is_parent',
        'is_removable',
        'sort_order',
    ];

    protected $casts = [
        'is_parent' => 'boolean',
        'is_removable' => 'boolean',
    ];

    public function forecast()
    {
        return $this->belongsTo(CashForecast::class, 'cash_forecast_id');
    }
}
