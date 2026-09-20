<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_json_ok(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }
}
