<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_include_non_leaking_browser_security_headers(): void
    {
        $response = $this->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function test_security_headers_are_present_on_health_response_without_api_cache_policy(): void
    {
        $response = $this->get('/up');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['status']);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('fonts.bunny.net', $response->getContent());
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $response->getContent());
    }
}
