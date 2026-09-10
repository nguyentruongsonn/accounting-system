<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AuditLog extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'correlation_id',
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'json',
        'new_values' => 'json',
        'metadata' => 'json',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit logs are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit logs are append-only and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetModel(): MorphTo
    {
        return $this->morphTo('model');
    }
}
