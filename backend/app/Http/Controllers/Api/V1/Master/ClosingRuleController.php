<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\ClosingRule;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClosingRuleController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $masterAudit) {}

    /**
     * Return the tenant-scoped closing catalogue for internal review.
     *
     * This is configuration/evidence only. Period-close execution remains
     * behind its separate approved-mapping/readiness gate.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $rules = ClosingRule::query()
            ->where('company_id', $companyId)
            ->orderBy('sequence')
            ->orderBy('rule_code')
            ->get();

        return response()->json([
            'data' => $rules,
            'meta' => [
                'total' => $rules->count(),
                'classification' => 'internal_account_catalogue',
                'execution_authorized' => false,
                'approval_required' => true,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $rule = ClosingRule::where('company_id', $companyId)->findOrFail($id);

        return response()->json([
            'data' => $rule,
            'meta' => ['execution_authorized' => false, 'approval_required' => true],
        ]);
    }

    /**
     * Create an internal closing-rule catalogue row. This never executes a
     * period close; execution remains behind the separate readiness gate.
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $accountRule = fn () => Rule::exists('chart_of_accounts', 'code')
            ->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_parent', false)
                ->whereNull('deleted_at'));

        $validated = $request->validate([
            // closing_rules is a hard-delete catalogue (no deleted_at column);
            // scope uniqueness to the tenant without querying a non-existent
            // soft-delete field.
            'rule_code' => ['required', 'string', 'max:50', Rule::unique('closing_rules', 'rule_code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'rule_name' => ['required', 'string', 'max:255'],
            'rule_type' => ['required', Rule::in(['revenue', 'expense', 'result'])],
            'debit_account' => ['required', 'string', 'max:20', $accountRule()],
            'credit_account' => ['required', 'string', 'max:20', $accountRule()],
            'source_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'target_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'closing_side' => ['required', Rule::in(['debit', 'credit', 'both'])],
            'transfer_type' => ['required', Rule::in(['turnover', 'balance_debit', 'balance_credit', 'formula'])],
            'sequence' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);

        $validated['company_id'] = $companyId;
        $rule = $this->masterAudit->create(new ClosingRule, $validated, 'closing_rule.created');

        return response()->json([
            'data' => $rule,
            'message' => 'Tạo quy tắc kết chuyển thành công',
            'meta' => ['execution_authorized' => false, 'approval_required' => true],
        ], 201);
    }

    /**
     * Update an internal closing-rule catalogue row within the actor tenant.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $rule = ClosingRule::where('company_id', $companyId)->findOrFail($id);
        $accountRule = fn () => Rule::exists('chart_of_accounts', 'code')
            ->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_parent', false)
                ->whereNull('deleted_at'));

        $validated = $request->validate([
            'rule_code' => ['sometimes', 'string', 'max:50', Rule::unique('closing_rules', 'rule_code')->ignore($rule->id)->where(fn ($query) => $query->where('company_id', $companyId))],
            'rule_name' => ['sometimes', 'string', 'max:255'],
            'rule_type' => ['sometimes', Rule::in(['revenue', 'expense', 'result'])],
            'debit_account' => ['sometimes', 'required', 'string', 'max:20', $accountRule()],
            'credit_account' => ['sometimes', 'required', 'string', 'max:20', $accountRule()],
            'source_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'target_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'closing_side' => ['sometimes', Rule::in(['debit', 'credit', 'both'])],
            'transfer_type' => ['sometimes', Rule::in(['turnover', 'balance_debit', 'balance_credit', 'formula'])],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);

        $rule = $this->masterAudit->update($rule, $validated, 'closing_rule.updated');

        return response()->json([
            'data' => $rule,
            'message' => 'Cập nhật quy tắc kết chuyển thành công',
            'meta' => ['execution_authorized' => false, 'approval_required' => true],
        ]);
    }

    /**
     * Delete an internal catalogue row. It does not mutate any journal.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $rule = ClosingRule::where('company_id', $companyId)->findOrFail($id);
        $this->masterAudit->delete($rule, 'closing_rule.deleted');

        return response()->json(['message' => 'Xóa quy tắc kết chuyển thành công']);
    }
}
