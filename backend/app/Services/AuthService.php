<?php

namespace App\Services;

use App\Models\AuthRefreshToken;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

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

        try {
            $this->assertCanonicalIdentity($user);
        } catch (Exception) {
            // Keep the same public response as invalid credentials so the
            // login endpoint cannot enumerate legacy or multi-role accounts.
            throw new Exception('Thông tin đăng nhập không chính xác.');
        }

        return $this->issueCredentials($user);
    }

    /** @return array<string,mixed> */
    public function refresh(?string $rawRefreshToken): array
    {
        if (! is_string($rawRefreshToken) || trim($rawRefreshToken) === '') {
            throw new Exception('Phiên đăng nhập đã hết hạn.');
        }

        $tokenHash = hash('sha256', $rawRefreshToken);

        return DB::transaction(function () use ($tokenHash): array {
            $refreshToken = AuthRefreshToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $refreshToken || $refreshToken->revoked_at !== null || $refreshToken->expires_at->isPast()) {
                throw new Exception('Phiên đăng nhập đã hết hạn.');
            }

            $user = $refreshToken->user;
            try {
                $this->assertCanonicalIdentity($user);
            } catch (Exception $exception) {
                $refreshToken->forceFill(['revoked_at' => now()])->save();
                throw $exception;
            }

            $refreshToken->forceFill([
                'last_used_at' => now(),
                'revoked_at' => now(),
            ])->save();

            return $this->issueCredentials($user);
        });
    }

    public function revokeRefreshToken(?string $rawRefreshToken): void
    {
        if (! is_string($rawRefreshToken) || trim($rawRefreshToken) === '') {
            return;
        }

        AuthRefreshToken::query()
            ->where('token_hash', hash('sha256', $rawRefreshToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function refreshTokenLifetimeMinutes(): int
    {
        return $this->refreshTokenLifetimeDays() * 24 * 60;
    }

    /** @return array<string,mixed> */
    private function issueCredentials(User $user): array
    {
        $token = $user->createToken(
            'auth_token',
            ['api'],
            now()->addMinutes($this->accessTokenLifetimeMinutes()),
        )->plainTextToken;
        $rawRefreshToken = bin2hex(random_bytes(32));

        AuthRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawRefreshToken),
            'expires_at' => now()->addDays($this->refreshTokenLifetimeDays()),
        ]);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'token' => $token,
            'refresh_token' => $rawRefreshToken,
        ];
    }

    private function assertCanonicalIdentity(User $user): void
    {
        $roles = $user->getRoleNames()->sort()->values()->all();
        if (count($roles) !== 1 || ! in_array($roles[0], self::CANONICAL_ROLES, true)) {
            throw new Exception('Tài khoản không có vai trò hợp lệ.');
        }
    }

    private function accessTokenLifetimeMinutes(): int
    {
        return max(1, (int) config('sanctum.expiration', 15));
    }

    private function refreshTokenLifetimeDays(): int
    {
        return max(1, (int) config('auth.refresh_token.ttl_days', 30));
    }
}
