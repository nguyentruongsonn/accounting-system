<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountAccessRevoker;
use App\Support\TwoRolePermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ConvertTwoRoles extends Command
{
    protected $signature = 'access:convert-two-roles {--apply : Apply the previewed conversion} {--confirm-admin=* : Explicitly confirmed active admin user IDs, one or more per company} {--confirm-accountant=* : Explicitly confirmed active accountant user IDs, one or more per company}';

    protected $description = 'Preview a non-destructive two-role conversion; never automatically promote legacy identities.';

    public function handle(): int
    {
        $adminIds = $this->normaliseIds($this->option('confirm-admin'), 'admin');
        $accountantIds = $this->normaliseIds($this->option('confirm-accountant'), 'accountant');
        if ($adminIds === null || $accountantIds === null) {
            return self::FAILURE;
        }
        if (array_intersect($adminIds, $accountantIds) !== []) {
            $this->error('The same identity cannot be confirmed as both admin and accountant.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            // Explicit allowlist, safe to redirect to an access-review artifact.
            $this->line(json_encode(['mode' => 'preview', 'users' => User::with('roles')->orderBy('id')->get()->map(fn (User $user) => [
                'id' => $user->id, 'company_id' => $user->company_id,
                'name' => $user->name, 'email' => $user->email,
                'is_active' => $user->is_active, 'roles' => $user->getRoleNames(),
                'proposed_action' => $this->action($user, $adminIds, $accountantIds),
            ])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        try {
            DB::transaction(function () use ($adminIds, $accountantIds): void {
                $users = User::orderBy('id')->lockForUpdate()->get();
                $confirmedIds = array_values(array_unique(array_merge($adminIds, $accountantIds)));
                $confirmed = $users->whereIn('id', $confirmedIds);
                if ($adminIds === [] || $confirmed->count() !== count($confirmedIds) || $confirmed->contains(fn (User $user) => ! $user->is_active || $user->company_id === null)) {
                    throw new RuntimeException('Apply requires explicitly confirmed, existing active identities with company assignments and at least one admin.');
                }
                foreach ($users->pluck('company_id')->filter()->unique() as $company) {
                    if (! $users->whereIn('id', $adminIds)->contains('company_id', $company)) {
                        throw new RuntimeException('Every company with users requires a confirmed active admin.');
                    }
                }
                TwoRolePermissions::seed();
                foreach ($users as $user) {
                    $action = $this->action($user, $adminIds, $accountantIds);
                    if ($action === 'confirm-admin') {
                        $user->syncRoles('admin');
                        $user->syncPermissions([]);
                    } elseif ($action === 'confirm-accountant') {
                        $user->syncRoles('accountant');
                        $user->syncPermissions([]);
                    } elseif ($action === 'keep-accountant') {
                        $user->syncPermissions([]);
                    } else {
                        // Retain legacy role links and historical user IDs for review.
                        $user->forceFill(['is_active' => false])->save();
                    }
                    app(AccountAccessRevoker::class)->revoke($user);
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Conversion applied. No users or historical records deleted. All prior credentials revoked.');

        return self::SUCCESS;
    }

    private function action(User $user, array $adminIds, array $accountantIds): string
    {
        if (in_array($user->id, $adminIds, true)) {
            return 'confirm-admin';
        }
        if (in_array($user->id, $accountantIds, true)) {
            return 'confirm-accountant';
        }

        return $user->company_id !== null && $user->getRoleNames()->all() === ['accountant']
            ? 'keep-accountant' : 'deactivate-for-review';
    }

    /** @return list<int>|null */
    private function normaliseIds(mixed $values, string $role): ?array
    {
        $values = is_array($values) ? $values : [];
        if (collect($values)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error("Confirmed {$role} IDs must be positive integers.");

            return null;
        }

        return array_values(array_unique(array_map('intval', $values)));
    }
}
