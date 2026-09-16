<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashForecast extends Model
{
    use BelongsToCompany, HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        // Keep direct Eloquent callers from inheriting the legacy person-name
        // database default when no creator evidence is supplied.
        'creator' => 'ACCOUNTANT',
    ];

    protected $fillable = [
        'company_id',
        'period_name',
        'from_date',
        'to_date',
        'creator',
        'created_date',
        'opening_balance',
        'expected_inflow',
        'expected_outflow',
        'closing_balance',
    ];

    public function items()
    {
        return $this->hasMany(CashForecastItem::class)->orderBy('sort_order');
    }
}
