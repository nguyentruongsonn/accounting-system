<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GlobalApiErrorBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_api_route_has_a_safe_correlated_error_even_when_debug_is_enabled(): void
    {
        // The production incident was observed with debug output enabled. Keep
        // this regression test explicit so a future environment change cannot
        // silently reintroduce the framework trace renderer.
        $this->app['config']->set('app.debug', true);

        $response = $this->getJson('/api/v1/security-probe-route-that-does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['error', 'request_id'])
            ->assertJsonPath('error', 'Resource not found.');

        $requestId = $response->json('request_id');
        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));

        $body = $response->getContent();
        foreach (['exception', 'file', 'line', 'trace', 'vendor', 'NotFoundHttpException'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }
}
