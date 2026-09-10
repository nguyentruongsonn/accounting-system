<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Period extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_year_id',
        'period',
        'period_number',
        'name',
        'start_date',
        'end_date',
        'status',
        'is_closed',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'period' => 'integer',
        'period_number' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
