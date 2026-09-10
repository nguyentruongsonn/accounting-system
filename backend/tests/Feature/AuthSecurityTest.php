<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_never_writes_plaintext_password_to_logs(): void
    {
        $sentinelPassword = 'SENTINEL-secret-never-log-9!';
        $user = User::factory()->create([
            'email' => 'security-log@example.test',
            'password' => 'correct-password',
        ]);

        Log::spy();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $sentinelPassword,
        ])->assertUnauthorized();

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            Log::shouldNotHaveReceived($level, function ($message) use ($sentinelPassword) {
                return str_contains((string) $message, $sentinelPassword);
            });
        }
    }

    public function test_login_is_rate_limited_after_five_attempts_per_email_and_ip(): void
    {
        $user = User::factory()->create([
            'email' => 'rate-limit@example.test',
            'password' => 'correct-password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_issued_token_is_scoped_expires_and_logout_revokes_only_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'token-security@example.test',
            'password' => 'correct-password',
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJsonStructure(['user', 'token']);

        $plainTextToken = $response->json('token');
        $accessToken = PersonalAccessToken::findToken($plainTextToken);

        $this->assertNotNull($accessToken);
        $this->assertSame(['api'], $accessToken->abilities);
        $this->assertNotNull($accessToken->expires_at);
        $this->assertTrue($accessToken->expires_at->between(
            now()->addMinutes(479),
            now()->addMinutes(481),
        ));

        $otherToken = $user->createToken('other-session', ['api'], now()->addMinutes(480))->accessToken;

        $this->withToken($plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Đăng xuất thành công']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->id]);

        app('auth')->forgetGuards();

        $this->withToken($plainTextToken)
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized();
    }
}
