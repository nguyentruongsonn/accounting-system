<?php

namespace App\Http\Resources;

use App\Models\ApprovedAccountMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovedAccountMapping */
class ApprovedAccountMappingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accounting_policy_version_id' => $this->accounting_policy_version_id,
            'mapping_key' => $this->mapping_key,
            'mapping_context' => $this->mapping_context,
            'context_hash' => $this->context_hash,
            'account_role' => $this->account_role,
            'account_code' => $this->account_code,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'regulatory_dependencies' => $this->regulatory_dependencies,
            'status' => $this->status,
            'contract_hash' => $this->contract_hash,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toISOString(),
            'is_immutable' => $this->approved_at !== null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'limitation' => 'Owner-supplied mapping evidence only; this endpoint does not provide or certify TT99 account mappings.',
        ];
    }
}
