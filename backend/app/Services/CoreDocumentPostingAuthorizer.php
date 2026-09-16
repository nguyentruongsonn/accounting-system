<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class CoreDocumentPostingAuthorizer
{
    /**
     * Resolve authorization from persisted identity state, never from role or
     * permission relations cached on the request principal.
     *
     * @return array{authorization_model:string,authorization:array{actor_id:int,actor_role:string,permission:string,company_id:int,account_active:bool}}
     */
    public function authorize(string $permission, int $companyId): array
    {
        $actorId = auth()->id();
        $actor = $actorId === null
            ? null
            : User::query()->with(['roles.permissions', 'permissions'])->find($actorId);

        if ($actor === null || ! $actor->accountIsActive()) {
            throw new AuthorizationException('An active account is required to post this document.');
        }

        $roles = $actor->getRoleNames()->sort()->values()->all();
        if (count($roles) !== 1 || ! in_array($roles[0], ['accountant', 'admin'], true)) {
            throw new AuthorizationException('Posting requires exactly one canonical accountant or admin role.');
        }

        if ((int) $actor->company_id !== $companyId) {
            throw new AuthorizationException('The document does not belong to the posting actor tenant.');
        }

        if (! $actor->can($permission)) {
            throw new AuthorizationException('The posting actor does not have the required document permission.');
        }

        return [
            'authorization_model' => 'direct_two_role',
            'authorization' => [
                'actor_id' => (int) $actor->id,
                'actor_role' => $roles[0],
                'permission' => $permission,
                'company_id' => $companyId,
                'account_active' => true,
            ],
        ];
    }
}
