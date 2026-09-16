<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\AccountReferenceService;
use App\Services\AccountService;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountController extends Controller
{
    protected AccountService $service;

    public function __construct(AccountService $service, private readonly MasterDataAuditService $masterAudit)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        if ($request->has('include_inactive') && is_string($request->input('include_inactive'))) {
            $normalizedIncludeInactive = filter_var(
                $request->input('include_inactive'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            // Keep invalid strings invalid so Laravel's boolean rule still
            // rejects them instead of silently converting them to null.
            if ($normalizedIncludeInactive !== null) {
                $request->merge(['include_inactive' => $normalizedIncludeInactive]);
            }
        }
        $filters = $request->validate([
            'search' => 'nullable|string|max:255',
            'include_inactive' => 'nullable|boolean',
        ]);
        $accounts = $this->service->getAll(
            $companyId,
            $filters['search'] ?? null,
            (bool) ($filters['include_inactive'] ?? true),
        );

        return response()->json($accounts);
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense', 'revenue_deduction'])],
            'nature' => ['required', Rule::in(['debit', 'credit', 'amphibious'])],
            'level' => 'nullable|integer|min:1|max:255',
            'parent_code' => ['nullable', 'string', Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'is_parent' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string',
        ]);
        $validated['company_id'] = $companyId;

        $account = DB::transaction(function () use ($validated, $companyId) {
            $this->service->lockHierarchy($companyId);
            $parent = $this->service->getParent($validated['parent_code'] ?? null, $companyId);
            $validated['level'] = $parent ? $parent->level + 1 : 1;
            $validated['is_parent'] = false;
            $account = $this->masterAudit->create(new ChartOfAccount, $validated, 'chart_of_account.created');
            if ($parent !== null && ! $parent->is_parent) {
                $this->masterAudit->update($parent, ['is_parent' => true], 'chart_of_account.parent_marked');
            }

            return $account;
        });

        return response()->json($account, 201);
    }

    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $account = $this->service->getById((int) $id, $companyId);

        return response()->json($account);
    }

    public function update(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $identityErrors = [];
        if ($request->exists('code')) {
            $identityErrors['code'] = 'Mã tài khoản là thông tin định danh và không thể thay đổi.';
        }
        if ($request->exists('company_id')) {
            $identityErrors['company_id'] = 'Công ty của tài khoản là thông tin định danh và không thể thay đổi.';
        }
        if ($identityErrors !== []) {
            throw ValidationException::withMessages($identityErrors);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'name_en' => 'sometimes|nullable|string|max:255',
            'type' => ['sometimes', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense', 'revenue_deduction'])],
            'nature' => ['sometimes', Rule::in(['debit', 'credit', 'amphibious'])],
            'parent_code' => ['sometimes', 'nullable', 'string', Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'is_active' => 'boolean',
            'description' => 'sometimes|nullable|string',
        ]);

        $account = DB::transaction(function () use ($id, $validated, $companyId) {
            $this->service->lockHierarchy($companyId);
            $existing = $this->service->getById((int) $id, $companyId);
            $oldParentCode = $existing->parent_code;
            $newParentCode = array_key_exists('parent_code', $validated)
                ? $validated['parent_code']
                : $oldParentCode;
            $this->service->assertParentDoesNotCreateCycle($existing, $newParentCode, $companyId);
            $newParent = $this->service->getParent($newParentCode, $companyId);
            $validated['level'] = $newParent ? $newParent->level + 1 : 1;
            $account = $this->masterAudit->update($existing, $validated, 'chart_of_account.updated');

            if ($newParent !== null && ! $newParent->is_parent) {
                $this->masterAudit->update($newParent, ['is_parent' => true], 'chart_of_account.parent_marked');
            }

            foreach ($this->service->descendants($account, $companyId) as $descendant) {
                $parent = $this->service->getParent($descendant->parent_code, $companyId);
                $expectedLevel = $parent ? $parent->level + 1 : 1;
                if ($descendant->level !== $expectedLevel) {
                    $this->masterAudit->update($descendant, ['level' => $expectedLevel], 'chart_of_account.level_recalculated');
                }
            }

            if ($oldParentCode !== null && $oldParentCode !== $account->parent_code) {
                $oldParent = $this->service->getParent($oldParentCode, $companyId);
                if ($oldParent !== null && ! $this->service->hasChildren($oldParent, $companyId)) {
                    $this->masterAudit->update($oldParent, ['is_parent' => false], 'chart_of_account.parent_unmarked');
                }
            }

            return $account->fresh();
        });

        return response()->json($account);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        DB::transaction(function () use ($id, $companyId): void {
            $this->service->lockHierarchy($companyId);
            $account = $this->service->getById((int) $id, $companyId);
            if ($this->service->hasChildren($account, $companyId)) {
                throw ValidationException::withMessages([
                    'account' => 'Không thể xóa tài khoản đang có tài khoản con.',
                ]);
            }
            $parentCode = $account->parent_code;
            $this->service->assertUnused($account, $companyId);
            $this->masterAudit->delete($account, 'chart_of_account.deleted');

            if ($parentCode !== null) {
                $parent = $this->service->getParent($parentCode, $companyId);
                if ($parent !== null && ! $this->service->hasChildren($parent, $companyId)) {
                    $this->masterAudit->update($parent, ['is_parent' => false], 'chart_of_account.parent_unmarked');
                }
            }
        });

        return response()->json(null, 204);
    }

    public function transfer(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'target_code' => ['required', 'string', Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at'))],
            'preview' => 'sometimes|boolean',
            'preview_token' => 'sometimes|string|size:64',
        ]);
        $result = DB::transaction(function () use ($id, $validated, $companyId) {
            $this->service->lockHierarchy($companyId);
            $source = $this->service->getById((int) $id, $companyId);
            $target = $this->service->getParent($validated['target_code'], $companyId);
            if ($target === null || $target->code === $source->code) {
                throw ValidationException::withMessages(['target_code' => 'Phải chọn tài khoản đích khác tài khoản nguồn.']);
            }
            if ($this->service->hasChildren($source, $companyId)) {
                throw ValidationException::withMessages(['account' => 'Không thể chuyển tài khoản đang có tài khoản con.']);
            }
            if ($this->service->hasChildren($target, $companyId)) {
                throw ValidationException::withMessages(['target_code' => 'Chọn tài khoản chi tiết, không chọn tài khoản tổng hợp.']);
            }
            if ($validated['preview'] ?? false) {
                $references = app(AccountReferenceService::class)->inspect($companyId, $source->code, true);

                return ['preview' => true, 'account' => $source, 'target' => $target,
                    'affected_references' => array_sum($references), 'references' => $references,
                    'preview_token' => $this->transferPreviewToken($companyId, $source, $target->code, $references)];
            }
            $previewToken = $validated['preview_token'] ?? null;
            if (! is_string($previewToken) || trim($previewToken) === '') {
                throw new ConflictHttpException('Phải xem trước phạm vi ảnh hưởng trước khi chuyển tài khoản.');
            }
            $references = app(AccountReferenceService::class)->inspect($companyId, $source->code, true);
            $expectedToken = $this->transferPreviewToken($companyId, $source, $target->code, $references);
            if (! hash_equals($expectedToken, $previewToken)) {
                throw new ConflictHttpException('Phạm vi tham chiếu đã thay đổi hoặc bản xem trước đã hết hiệu lực. Hãy xem trước lại.');
            }
            $affected = $this->service->transferReferences($source, $target, $companyId);
            $source = $this->masterAudit->update($source, ['is_active' => false], 'chart_of_account.transferred');

            return ['account' => $source, 'target' => $target->fresh(), 'affected_references' => $affected];
        });

        return response()->json($result);
    }

    /** @param array<string,int> $references */
    private function transferPreviewToken(int $companyId, ChartOfAccount $source, string $targetCode, array $references): string
    {
        $payload = json_encode([
            'company_id' => $companyId,
            'source_id' => (int) $source->id,
            'source_code' => (string) $source->code,
            'target_code' => $targetCode,
            'references' => $references,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
