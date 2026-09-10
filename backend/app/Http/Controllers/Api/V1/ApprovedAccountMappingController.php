<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApprovedAccountMappingRequest;
use App\Http\Requests\UpdateApprovedAccountMappingRequest;
use App\Http\Resources\ApprovedAccountMappingResource;
use App\Models\AccountingPolicyVersion;
use App\Models\ApprovedAccountMapping;
use App\Services\AccountMappingPolicyCompatibilityService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Tenant-scoped management workbench for owner-supplied mapping evidence. */
class ApprovedAccountMappingController extends Controller
{
    public function __construct(
        private readonly ApprovedAccountMappingLifecycleService $lifecycle,
        private readonly AccountMappingPolicyCompatibilityService $compatibility,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApprovedAccountMapping::withoutGlobalScope('company')
            ->where('company_id', TenantContext::companyId($request))->orderByDesc('id');
        foreach (['status', 'mapping_key', 'account_role', 'accounting_policy_version_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return response()->json(ApprovedAccountMappingResource::collection($query->paginate(min((int) $request->input('per_page', 50), 100))));
    }

    /**
     * Return only tenant-owned, integrity-checked policies that can be
     * selected while preparing a new mapping draft. Policy details remain
     * read-only here; creation and approval still use their existing gates.
     */
    public function policies(Request $request): JsonResponse
    {
        $policies = AccountingPolicyVersion::withoutGlobalScope('company')
            ->where('company_id', TenantContext::companyId($request))
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->whereNotNull('contract_hash')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get([
                'id', 'accounting_regime_profile_id', 'policy_key', 'policy_version',
                'effective_from', 'effective_to', 'status', 'approved_at',
            ]);

        return response()->json([
            'data' => $policies->map(fn (AccountingPolicyVersion $policy): array => [
                'id' => $policy->id,
                'accounting_regime_profile_id' => $policy->accounting_regime_profile_id,
                'policy_key' => $policy->policy_key,
                'policy_version' => $policy->policy_version,
                'effective_from' => $policy->effective_from?->toDateString(),
                'effective_to' => $policy->effective_to?->toDateString(),
                'status' => $policy->status,
                'approved_at' => $policy->approved_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function show(Request $request, int $id): ApprovedAccountMappingResource
    {
        return new ApprovedAccountMappingResource($this->mapping($request, $id));
    }

    public function store(StoreApprovedAccountMappingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->compatibility->assertCompatible(
            TenantContext::companyId($request),
            (int) $validated['accounting_policy_version_id'],
            (string) $validated['mapping_key'],
        );
        $mapping = $this->lifecycle->createDraft($request->user(), $validated);
        $this->audit->record($mapping, 'approved_account_mapping.draft_created', [], $this->auditShape($mapping), null, ['control_plane' => 'owner_supplied_account_mapping']);

        return (new ApprovedAccountMappingResource($mapping))->response()->setStatusCode(201);
    }

    public function update(UpdateApprovedAccountMappingRequest $request, int $id): ApprovedAccountMappingResource
    {
        $mapping = $this->mapping($request, $id);
        if ($mapping->status !== 'draft' || $mapping->approved_at !== null) {
            throw new ConflictHttpException('Approved account mappings are immutable; create a successor draft.');
        }
        $before = $this->auditShape($mapping);
        $validated = $request->validated();
        $this->compatibility->assertCompatible(
            TenantContext::companyId($request),
            (int) ($validated['accounting_policy_version_id'] ?? $mapping->accounting_policy_version_id),
            (string) ($validated['mapping_key'] ?? $mapping->mapping_key),
        );
        $updated = $this->lifecycle->updateDraft($request->user(), $mapping, $validated);
        $this->audit->record($updated, 'approved_account_mapping.draft_updated', $before, $this->auditShape($updated), null, ['control_plane' => 'owner_supplied_account_mapping']);

        return new ApprovedAccountMappingResource($updated);
    }

    public function approve(Request $request, int $id): ApprovedAccountMappingResource
    {
        $mapping = $this->mapping($request, $id);
        if ($mapping->status !== 'draft' || $mapping->approved_at !== null) {
            throw new ConflictHttpException('Only an unsigned account-mapping draft may be approved.');
        }
        $this->compatibility->assertCompatible(
            TenantContext::companyId($request),
            (int) $mapping->accounting_policy_version_id,
            (string) $mapping->mapping_key,
        );
        $before = $this->auditShape($mapping);
        $approved = $this->lifecycle->approve($request->user(), $mapping);
        $this->audit->record($approved, 'approved_account_mapping.approved', $before, $this->auditShape($approved), null, ['control_plane' => 'owner_supplied_account_mapping', 'maker_checker_enforced' => true]);

        return new ApprovedAccountMappingResource($approved);
    }

    private function mapping(Request $request, int $id): ApprovedAccountMapping
    {
        return ApprovedAccountMapping::withoutGlobalScope('company')
            ->where('company_id', TenantContext::companyId($request))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function auditShape(ApprovedAccountMapping $mapping): array
    {
        return [
            'id' => $mapping->id, 'accounting_policy_version_id' => $mapping->accounting_policy_version_id,
            'mapping_key' => $mapping->mapping_key, 'context_hash' => $mapping->context_hash,
            'account_role' => $mapping->account_role, 'account_code' => $mapping->account_code,
            'effective_from' => $mapping->effective_from?->toDateString(), 'effective_to' => $mapping->effective_to?->toDateString(),
            'status' => $mapping->status, 'contract_hash' => $mapping->contract_hash,
        ];
    }
}
