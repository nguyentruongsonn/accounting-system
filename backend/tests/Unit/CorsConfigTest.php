<?php

namespace Tests\Unit;

use Tests\TestCase;

final class CorsConfigTest extends TestCase
{
    public function test_credentialed_cors_does_not_allow_unowned_vercel_deployments(): void
    {
        $patterns = config('cors.allowed_origins_patterns', []);

        self::assertNotContains('#^https://.*\\.vercel\\.app$#', $patterns);
    }
}
