<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountingPolicyRequest;
use App\Http\Requests\UpdateAccountingPolicyRequest;
use App\Http\Resources\AccountingPolicyVersionResource;
use App\Models\AccountingPolicyVersion;
use App\Models\AccountingRegimeProfile;
use App\Services\AccountingPolicyContractService;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\AuditService;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountingPolicyController extends Controller
{
    public function __construct(
        private readonly AccountingPolicyLifecycleService $lifecycle,
        private readonly AccountingPolicyContractService $contracts,
        private readonly AuditService $audit,
    ) {}

    public function profiles(Request $request): JsonResponse
    {
        $profiles = AccountingRegimeProfile::withoutGlobalScope('company')
            ->with('fiscalYear')
            ->where('company_id', TenantContext::companyId($request))
            ->orderByDesc('effective_from')
            ->get();

        return response()->json(['data' => $profiles->map(fn (AccountingRegimeProfile $profile): array => [
            'id' => $profile->id,
            'fiscal_year_id' => $profile->fiscal_year_id,
            'fiscal_year' => $profile->fiscalYear?->year,
            'regime' => $profile->regime?->value,
            'regime_label' => $profile->regime?->label(),
            'effective_from' => $profile->effective_from?->toDateString(),
            'effective_to' => $profile->effective_to?->toDateString(),
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = AccountingPolicyVersion::withoutGlobalScope('company')
            ->with('accountingRegimeProfile.fiscalYear')
            ->where('company_id', TenantContext::companyId($request))
            ->orderByDesc('id');
        foreach (['status', 'policy_key'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return AccountingPolicyVersionResource::collection(
            $query->paginate(min(max((int) $request->input('per_page', 50), 1), 100))
        )->response();
    }

    public function store(StoreAccountingPolicyRequest $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validated();
        $contract = $this->contracts->build(
            $companyId,
            $validated['policy_key'],
            $validated['source_accounts'] ?? [],
        );
        unset($validated['source_accounts']);
        $policy = $this->lifecycle->createDraft($request->user(), [
            ...$validated,
            'company_id' => $companyId,
            'posting_rule_contract' => $contract,
            'required_dimensions' => [],
        ]);
        $this->audit->record($policy, 'accounting_policy.draft_created', [], $this->auditShape($policy), null, [
            'control_plane' => 'accounting_policy',
            'json_contract_server_managed' => true,
        ]);

        return (new AccountingPolicyVersionResource($policy))->response()->setStatusCode(201);
    }

    public function approve(Request $request, int $id): AccountingPolicyVersionResource
    {
        $policy = $this->policy($request, $id);
        if ($policy->status !== 'draft' || $policy->approved_at !== null) {
            throw new ConflictHttpException('Only an unsigned accounting-policy draft may be approved.');
        }
        if ($policy->created_by !== null && (int) $policy->created_by === (int) $request->user()->id) {
            throw new AuthorizationException('The accounting policy maker cannot approve their own policy.');
        }
        $this->contracts->assertValid((int) $policy->company_id, $policy->posting_rule_contract ?? []);
        $before = $this->auditShape($policy);
        $approved = $this->lifecycle->approve($request->user(), $policy);
        $this->audit->record($approved, 'accounting_policy.approved', $before, $this->auditShape($approved), null, [
            'control_plane' => 'accounting_policy',
            'maker_checker_enforced' => true,
        ]);

        return new AccountingPolicyVersionResource($approved);
    }

    public function update(UpdateAccountingPolicyRequest $request, int $id): AccountingPolicyVersionResource
    {
        $policy = $this->policy($request, $id);
        if ($policy->status !== 'draft' || $policy->approved_at !== null) {
            throw new ConflictHttpException('Approved accounting policies are immutable; create a successor draft.');
        }
        $validated = $request->validated();
        $policyKey = (string) ($validated['policy_key'] ?? $policy->policy_key);
        $sources = $validated['source_accounts']
            ?? ($policyKey === 'posting.period_closing' ? ($policy->posting_rule_contract['source_accounts'] ?? []) : []);
        $contract = $this->contracts->build((int) $policy->company_id, $policyKey, $sources);
        unset($validated['source_accounts']);
        $before = $this->auditShape($policy);
        $updated = $this->lifecycle->updateDraft($request->user(), $policy, [
            ...$validated,
            'posting_rule_contract' => $contract,
        ]);
        $this->audit->record($updated, 'accounting_policy.draft_updated', $before, $this->auditShape($updated), null, [
            'control_plane' => 'accounting_policy',
            'json_contract_server_managed' => true,
        ]);

        return new AccountingPolicyVersionResource($updated);
    }

    private function policy(Request $request, int $id): AccountingPolicyVersion
    {
        return AccountingPolicyVersion::withoutGlobalScope('company')
            ->where('company_id', TenantContext::companyId($request))
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function auditShape(AccountingPolicyVersion $policy): array
    {
        return [
            'id' => $policy->id,
            'accounting_regime_profile_id' => $policy->accounting_regime_profile_id,
            'policy_key' => $policy->policy_key,
            'policy_version' => $policy->policy_version,
            'effective_from' => $policy->effective_from?->toDateString(),
            'effective_to' => $policy->effective_to?->toDateString(),
            'posting_rule_contract' => $policy->posting_rule_contract,
            'status' => $policy->status,
            'contract_hash' => $policy->contract_hash,
        ];
    }
}
