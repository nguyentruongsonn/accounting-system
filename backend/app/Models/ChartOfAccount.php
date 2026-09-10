<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartOfAccount extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'name_en',
        'parent_code',
        'type',
        'nature',
        'level',
        'is_parent',
        'is_active',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_parent' => 'boolean',
        'is_active' => 'boolean',
        'level' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
