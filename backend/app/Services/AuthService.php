<?php

namespace App\Services;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /** @var list<string> */
    private const CANONICAL_ROLES = ['admin', 'accountant'];

    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->accountIsActive()) {
            throw new Exception('Thông tin đăng nhập không chính xác.');
        }

        $isValid = Hash::check($password, $user->password);

        if (! $isValid) {
            throw new Exception('Thông tin đăng nhập không chính xác.');
        }

        // The production contract has exactly two application roles. Legacy
        // or multi-role identities must be explicitly reassigned before they
        // can authenticate; permissions alone must never create a third path.
        $roles = $user->getRoleNames()->sort()->values()->all();
        if (count($roles) !== 1 || ! in_array($roles[0], self::CANONICAL_ROLES, true)) {
            // Keep the same public response as invalid credentials so the
            // login endpoint cannot enumerate legacy or multi-role accounts.
            throw new Exception('Thông tin đăng nhập không chính xác.');
        }

        $expirationMinutes = max(1, (int) config('sanctum.expiration', 480));
        $token = $user->createToken(
            'auth_token',
            ['api'],
            now()->addMinutes($expirationMinutes),
        )->plainTextToken;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'token' => $token,
        ];
    }
}
