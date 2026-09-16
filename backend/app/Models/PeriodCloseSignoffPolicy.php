<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PeriodCloseSignoffPolicy extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'draft', 'separation_of_duties_required' => true];

    protected $fillable = ['company_id', 'policy_version', 'effective_from', 'effective_to', 'reviewer_roles', 'separation_of_duties_required', 'approval_key', 'created_by'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'reviewer_roles' => 'array', 'separation_of_duties_required' => 'boolean', 'activated_at' => 'immutable_datetime'];
}
