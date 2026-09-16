<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function getAll(?int $companyId = null, ?string $search = null, bool $includeInactive = true): Collection
    {
        $companyId = $this->requireCompanyId($companyId);

        $allAccounts = ChartOfAccount::where('company_id', $companyId)->orderBy('code')->get();
        $accounts = $includeInactive
            ? $allAccounts
            : $allAccounts->where('is_active', true)->values();
        $needle = mb_strtolower(trim((string) $search));
        if ($needle === '') {
            return $accounts;
        }

        $byCode = $allAccounts->keyBy('code');
        $includedCodes = [];

        foreach ($accounts as $account) {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $account->code,
                $account->name,
                $account->name_en,
            ])));
            if (! str_contains($haystack, $needle)) {
                continue;
            }

            $current = $account;
            while ($current !== null && ! isset($includedCodes[$current->code])) {
                $includedCodes[$current->code] = true;
                $current = $current->parent_code !== null
                    ? $byCode->get($current->parent_code)
                    : null;
            }
        }

        return $allAccounts
            ->filter(fn (ChartOfAccount $account): bool => isset($includedCodes[$account->code]))
            ->values();
    }

    public function getById(int $id, ?int $companyId = null): ChartOfAccount
    {
        $companyId = $this->requireCompanyId($companyId);

        return ChartOfAccount::where('company_id', $companyId)->findOrFail($id);
    }

    public function create(array $data): ChartOfAccount
    {
        $companyId = $this->requireCompanyId($data['company_id'] ?? null);

        return DB::transaction(function () use ($data, $companyId): ChartOfAccount {
            // Direct service callers must receive the same tenant-wide chart
            // lock as the HTTP controller.  Otherwise a queue/command could
            // race a reparent/delete operation and write a stale hierarchy.
            $this->lockHierarchy($companyId);

            $data['company_id'] = $companyId;
            $parentCode = $this->normalizeParentCode($data['parent_code'] ?? null);
            $parent = $this->resolveParentForMutation($parentCode, $companyId);
            $data['parent_code'] = $parent?->code;
            $data['level'] = $parent ? $parent->level + 1 : max(1, (int) ($data['level'] ?? 1));
            // A newly created account is a leaf until a child is actually
            // added.  The controller follows the same invariant.
            $data['is_parent'] = false;

            if (ChartOfAccount::withTrashed()->where('company_id', $companyId)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages([
                    'code' => 'Mã tài khoản đã tồn tại trong công ty này.',
                ]);
            }

            $account = ChartOfAccount::create($data);
            if ($parent !== null && ! $parent->is_parent) {
                $parent->update(['is_parent' => true]);
            }

            return $account;
        });
    }

    public function update(int $id, array $data, ?int $companyId = null): ChartOfAccount
    {
        $companyId = $this->requireCompanyId($companyId ?? ($data['company_id'] ?? null));

        return DB::transaction(function () use ($id, $data, $companyId): ChartOfAccount {
            $this->lockHierarchy($companyId);
            $account = ChartOfAccount::where('company_id', $companyId)->findOrFail($id);

            // Code and tenant ownership form the identity of a chart row.  A
            // direct service caller must not mutate either field to bypass
            // reference protection or move the row across companies.
            $identityErrors = [];
            if (array_key_exists('code', $data)) {
                $identityErrors['code'] = 'Mã tài khoản là thông tin định danh và không thể thay đổi.';
            }
            if (array_key_exists('company_id', $data)) {
                $identityErrors['company_id'] = 'Công ty của tài khoản là thông tin định danh và không thể thay đổi.';
            }
            if ($identityErrors !== []) {
                throw ValidationException::withMessages($identityErrors);
            }

            $parentCode = array_key_exists('parent_code', $data)
                ? $this->normalizeParentCode($data['parent_code'])
                : $account->parent_code;
            $this->assertParentDoesNotCreateCycle($account, $parentCode, $companyId);
            $newParent = $this->resolveParentForMutation($parentCode, $companyId);
            $oldParentCode = $account->parent_code;

            $mutable = array_intersect_key($data, array_flip([
                'name', 'name_en', 'type', 'nature', 'parent_code', 'is_active', 'description',
            ]));
            if (array_key_exists('parent_code', $data)) {
                $mutable['parent_code'] = $newParent?->code;
            }
            if ($newParent !== null) {
                $mutable['level'] = $newParent->level + 1;
            } elseif (array_key_exists('parent_code', $data)) {
                $mutable['level'] = 1;
            }

            $account->update($mutable);
            if ($newParent !== null && ! $newParent->is_parent) {
                $newParent->update(['is_parent' => true]);
            }
            if ($oldParentCode !== null && $oldParentCode !== $account->parent_code) {
                $oldParent = $this->resolveParentForMutation($oldParentCode, $companyId);
                if ($oldParent !== null && ! $this->hasChildren($oldParent, $companyId)) {
                    $oldParent->update(['is_parent' => false]);
                }
            }

            foreach ($this->descendants($account, $companyId) as $descendant) {
                $parent = $this->resolveParentForMutation($descendant->parent_code, $companyId);
                $expectedLevel = $parent ? $parent->level + 1 : 1;
                if ($descendant->level !== $expectedLevel) {
                    $descendant->update(['level' => $expectedLevel]);
                }
            }

            return $account->fresh();
        });
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        DB::transaction(function () use ($id, $companyId): void {
            $this->lockHierarchy($companyId);
            $account = $this->getById($id, $companyId);
            $this->assertUnused($account, $companyId);
            if ($this->hasChildren($account, $companyId)) {
                throw ValidationException::withMessages(['account' => 'Không thể xóa tài khoản đang có tài khoản con.']);
            }
            $account->delete();
        });
    }

    public function getParent(?string $parentCode, ?int $companyId = null): ?ChartOfAccount
    {
        $companyId = $this->requireCompanyId($companyId);
        if ($parentCode === null || trim($parentCode) === '') {
            return null;
        }

        return ChartOfAccount::where('company_id', $companyId)
            ->where('code', $parentCode)
            ->firstOrFail();
    }

    public function hasChildren(ChartOfAccount $account, ?int $companyId = null): bool
    {
        $companyId = $this->requireCompanyId($companyId);

        return ChartOfAccount::where('company_id', $companyId)
            ->where('parent_code', $account->code)
            ->exists();
    }

    public function assertParentDoesNotCreateCycle(ChartOfAccount $account, ?string $parentCode, ?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        $visited = [];

        while ($parentCode !== null && $parentCode !== '') {
            if ($parentCode === $account->code || isset($visited[$parentCode])) {
                throw ValidationException::withMessages([
                    'parent_code' => 'Tài khoản cha không được là chính tài khoản hoặc tài khoản con của nó.',
                ]);
            }

            $visited[$parentCode] = true;
            $parentCode = ChartOfAccount::where('company_id', $companyId)
                ->where('code', $parentCode)
                ->value('parent_code');
        }
    }

    /** @return Collection<int, ChartOfAccount> */
    public function descendants(ChartOfAccount $account, ?int $companyId = null): Collection
    {
        $companyId = $this->requireCompanyId($companyId);
        $all = ChartOfAccount::where('company_id', $companyId)->orderBy('code')->get();
        $childrenByParent = $all->groupBy('parent_code');
        $result = collect();
        $queue = [$account->code];

        while ($queue !== []) {
            $parentCode = array_shift($queue);
            foreach ($childrenByParent->get($parentCode, collect()) as $child) {
                $result->push($child);
                $queue[] = $child->code;
            }
        }

        return $result;
    }

    /** Lock the tenant hierarchy before validating a structural write. */
    public function lockHierarchy(?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        // Lock a stable tenant row as well as existing chart rows.  A chart
        // with zero accounts has no row to lock, so row-locking only the
        // hierarchy would leave concurrent first-account writes unprotected.
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        ChartOfAccount::where('company_id', $companyId)
            ->lockForUpdate()
            ->get(['id']);
    }

    private function normalizeParentCode(mixed $parentCode): ?string
    {
        $normalized = trim((string) ($parentCode ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function resolveParentForMutation(?string $parentCode, int $companyId): ?ChartOfAccount
    {
        if ($parentCode === null) {
            return null;
        }

        $parent = ChartOfAccount::where('company_id', $companyId)
            ->where('code', $parentCode)
            ->whereNull('deleted_at')
            ->first();
        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_code' => 'Tài khoản cha không thuộc công ty hiện tại hoặc không còn tồn tại.',
            ]);
        }

        return $parent;
    }

    public function assertUnused(ChartOfAccount $account, int $companyId): void
    {
        if (app(AccountReferenceService::class)->inspect($this->requireCompanyId($companyId), $account->code) !== []) {
            throw ValidationException::withMessages(['account' => 'Tài khoản đã được sử dụng. Hãy ngừng sử dụng thay vì xóa.']);
        }
    }

    /** Transfer only mutable references in open periods, never posted history. */
    public function transferReferences(ChartOfAccount $from, ChartOfAccount $to, ?int $companyId = null): int
    {
        $companyId = $this->requireCompanyId($companyId);

        return app(AccountReferenceService::class)->transfer($companyId, $from->code, $to->code);
    }

    private function requireCompanyId(?int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolvedCompanyId = $companyId ?? $actorCompanyId;

        if ($resolvedCompanyId === null || $resolvedCompanyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $actorCompanyId !== (int) $resolvedCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return (int) $resolvedCompanyId;
    }
}
