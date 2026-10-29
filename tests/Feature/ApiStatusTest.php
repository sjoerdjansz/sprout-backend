<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiStatusTest extends TestCase
{
    public function test_status_endpoint_is_public_and_versioned(): void
    {
        $response = $this->getJson('/api/v1/status');

        $response->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_status_endpoint_allows_the_configured_frontend_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $response = $this->getJson('/api/v1/status', ['Origin' => 'http://localhost:5173']);

        $response->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
