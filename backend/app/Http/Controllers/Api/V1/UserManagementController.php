<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountAccessRevoker;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $this->requireAccountSchema();
        $users = User::where('company_id', TenantContext::companyId($request))->with('roles')->orderBy('id')->get();

        return response()->json(['data' => $users->map(fn (User $user) => $this->present($user))]);
    }

    public function store(Request $request)
    {
        $this->requireAccountSchema();
        $company = TenantContext::companyId($request);
        $data = $this->validated($request);
        $user = DB::transaction(function () use ($data, $company) {
            $user = new User;
            $user->forceFill(collect($data)->only(['name', 'email', 'password', 'is_active'])->all() + ['company_id' => $company, 'is_active' => true])->save();
            $user->syncRoles($data['role']);

            return $user;
        });

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->requireAccountSchema();
        $company = TenantContext::companyId($request);
        User::where('company_id', $company)->findOrFail($id);
        $data = $this->validated($request, $id);
        $user = DB::transaction(function () use ($company, $id, $data) {
            // Lock all tenant principals in stable order: simultaneous admin
            // demotions cannot both observe the other as still active.
            $users = User::where('company_id', $company)->orderBy('id')->lockForUpdate()->get();
            $user = $users->firstWhere('id', $id);
            abort_unless($user, 404);
            $remainsActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $user->is_active;
            if ($remainsActive && ! isset($data['role'])
                && ! $this->hasCanonicalRole($user)) {
                throw ValidationException::withMessages(['role' => 'Select admin or accountant before editing a legacy identity.']);
            }
            $losesAdmin = $user->hasRole('admin') && $user->is_active
                && ((isset($data['is_active']) && ! (bool) $data['is_active']) || (isset($data['role']) && $data['role'] !== 'admin'));
            if ($losesAdmin && $users->filter(fn (User $candidate) => $candidate->is_active && $candidate->hasRole('admin'))->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'At least one active admin must remain in this company.']);
            }
            $roleChanged = isset($data['role']) && ($user->getRoleNames()->all() !== [$data['role']] || $user->permissions()->exists());
            $user->forceFill(collect($data)->only(['name', 'email', 'password', 'is_active'])->all());
            $revoke = $roleChanged || $user->isDirty(['password', 'is_active', 'email']);
            $user->save();
            if (isset($data['role'])) {
                $user->syncRoles($data['role']);
                $user->syncPermissions([]);
            }
            if ($revoke) {
                app(AccountAccessRevoker::class)->revoke($user);
            }

            return $user;
        });

        return response()->json(['data' => $this->present($user)]);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $required = $id === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255', Rule::unique('users')->ignore($id)],
            'password' => [$required, 'string', 'confirmed', Password::min(12)->letters()->numbers()],
            'role' => [$required, Rule::in(['admin', 'accountant'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function requireAccountSchema(): void
    {
        abort_unless(Schema::hasColumns('users', ['is_active', 'auth_version']), 503,
            'User management requires the reviewed 2026_09_01_000001_add_account_status_to_users migration. Existing login remains available.');
    }

    private function hasCanonicalRole(User $user): bool
    {
        $roles = $user->getRoleNames()->all();

        return count($roles) === 1 && in_array($roles[0], ['admin', 'accountant'], true);
    }

    private function present(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'is_active' => $user->is_active, 'roles' => $user->getRoleNames()];
    }
}
