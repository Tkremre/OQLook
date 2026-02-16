<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_handles_http_requests(): void
    {
        $response = $this->get('/__healthcheck_unknown_route__');

        $response->assertStatus(404);
    }
}
