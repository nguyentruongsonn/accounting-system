<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReconciliationApiErrorBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsupported_method_is_a_correlated_non_leaking_client_error(): void
    {
        $response = $this->putJson('/api/v1/gl/reconciliations', [
            'period_id' => 1,
        ])->assertStatus(405)
            ->assertJsonPath('error', 'Method not allowed.');

        $this->assertCorrelatedError($response);
        $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
        $this->assertStringContainsString('POST', (string) $response->headers->get('Allow'));
        $this->assertStringNotContainsString('MethodNotAllowedHttpException', $response->getContent());
        $this->assertStringNotContainsString('vendor', $response->getContent());
    }

    private function assertCorrelatedError(TestResponse $response): void
    {
        $requestId = $response->json('request_id');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));
    }
}
