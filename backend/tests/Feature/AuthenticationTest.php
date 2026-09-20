<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_login_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
    }
}
