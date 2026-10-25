<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 15 (monitoring):
 * the public health endpoint answers ok with a live database and
 * exposes nothing sensitive.
 */
class OwnerWave15Test extends TestCase
{
    public function test_health_returns_ok_and_nothing_sensitive()
    {
        $response = $this->get(route('health'));
        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'database' => 'ok']);
        $body = $response->getContent();
        $this->assertStringNotContainsString('Laravel', $body);
        $this->assertStringNotContainsString('php', strtolower($body));
        $this->assertStringNotContainsString('mysql', strtolower($body));
        $this->assertStringNotContainsString('hackstra.app', $body);
    }
}
