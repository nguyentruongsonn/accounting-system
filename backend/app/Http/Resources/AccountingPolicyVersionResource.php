<?php

namespace App\Http\Resources;

use App\Models\AccountingPolicyVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountingPolicyVersion */
class AccountingPolicyVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accounting_regime_profile_id' => $this->accounting_regime_profile_id,
            'fiscal_year' => $this->whenLoaded('accountingRegimeProfile', fn () => $this->accountingRegimeProfile?->fiscalYear?->year),
            'policy_key' => $this->policy_key,
            'policy_version' => $this->policy_version,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'posting_rule_contract' => $this->posting_rule_contract,
            'required_dimensions' => $this->required_dimensions,
            'regulatory_dependencies' => $this->regulatory_dependencies,
            'status' => $this->status,
            'contract_hash' => $this->contract_hash,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toISOString(),
            'is_immutable' => $this->approved_at !== null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
