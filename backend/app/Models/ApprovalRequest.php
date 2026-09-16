<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRequest extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'pending', 'separation_of_duties_required' => true];

    protected $fillable = ['company_id', 'approval_policy_id', 'approval_key', 'subject_type', 'subject_id', 'separation_of_duties_required', 'policy_snapshot', 'request_evidence', 'requested_by', 'requested_at'];

    protected $casts = ['policy_snapshot' => 'array', 'request_evidence' => 'array', 'separation_of_duties_required' => 'boolean', 'requested_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRequestStep::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }
}
