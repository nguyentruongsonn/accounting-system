<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-local pointer to the one definition version currently selected for a
 * report key. It is intentionally separate from the immutable definition
 * evidence: moving the pointer never changes a published definition.
 */
class ManagementReportEffectiveDefinition extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'report_key',
        'management_report_definition_id',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ManagementReportDefinition::class, 'management_report_definition_id');
    }
}
