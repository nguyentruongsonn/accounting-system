<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApprovalPolicy extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'draft', 'separation_of_duties_required' => true];

    protected $fillable = ['company_id', 'approval_key', 'policy_version', 'effective_from', 'effective_to', 'separation_of_duties_required', 'steps', 'created_by'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'steps' => 'array', 'separation_of_duties_required' => 'boolean', 'activated_at' => 'immutable_datetime'];
}
