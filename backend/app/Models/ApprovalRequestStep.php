<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRequestStep extends Model
{
    protected $fillable = ['approval_request_id', 'step_order', 'required_approvals'];

    protected $casts = ['completed_at' => 'immutable_datetime'];

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }
}
