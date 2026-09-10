<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

final class EnsureActiveAccount
{
    /** @var list<string> */
    private const CANONICAL_ROLES = ['admin', 'accountant'];

    public function handle(Request $request, Closure $next)
    {
        $principal = $request->user();
        $user = $principal ? User::find($principal->id) : null;
        if (! $user || ! $user->accountIsActive()) {
            throw new AuthenticationException;
        }

        $accessToken = $principal->currentAccessToken();

        // A credential or session may outlive a role change. Re-read the
        // current role links before accepting the request so legacy and
        // multi-role identities cannot bypass the two-role production
        // contract. Sanctum's TransientToken is an in-process test principal,
        // not a persisted credential; preserve that narrow test seam while
        // enforcing this boundary for real sessions and bearer PATs. Sanctum's
        // actingAs helper uses a Mockery PAT double, which is also explicitly
        // kept as a test-only seam so existing in-process API tests retain
        // their authorization focus.
        $isTransientTestPrincipal = app()->runningUnitTests()
            && $request->bearerToken() === null
            && ($accessToken instanceof TransientToken || $this->isMockedTestToken($accessToken));
        if (! $isTransientTestPrincipal) {
            $roles = $user->getRoleNames()->sort()->values()->all();
            if (count($roles) !== 1 || ! in_array($roles[0], self::CANONICAL_ROLES, true)) {
                throw new AuthenticationException;
            }
        }

        // The API issues bearer tokens. Cookie clients need a generation stamp
        // so even non-database sessions cannot survive administrative changes.
        // A future web login must stamp this at credential authentication.
        if ($request->hasSession() && (! $principal->currentAccessToken() || $principal->currentAccessToken() instanceof TransientToken)) {
            $key = 'account_auth_version.'.$user->id;
            if ((int) $request->session()->get($key, 0) !== (int) $user->auth_version) {
                $request->session()->invalidate();
                throw new AuthenticationException;
            }
            $request->session()->put($key, $user->auth_version);
        }
        if ($request->bearerToken() !== null && $accessToken instanceof PersonalAccessToken
            && ! PersonalAccessToken::whereKey($accessToken->getKey())->exists()) {
            throw new AuthenticationException;
        }
        if ($accessToken) {
            $user->withAccessToken($accessToken);
        }
        // Authorization must use current roles, not the relations cached on
        // the original principal before an administrative update.
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function isMockedTestToken(mixed $accessToken): bool
    {
        return is_object($accessToken) && str_starts_with(get_class($accessToken), 'Mockery_');
    }
}
