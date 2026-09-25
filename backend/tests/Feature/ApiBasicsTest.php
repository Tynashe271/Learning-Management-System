<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The API answers in JSON however it is called, including from a browser that sends no Accept header. */
class ApiBasicsTest extends TestCase
{
    public function test_the_api_root_says_where_to_start(): void
    {
        $this->get('/api')->assertOk()->assertJsonPath('status', 'ok')->assertJsonStructure(['name', 'message']);
    }

    public function test_unauthenticated_requests_get_a_json_401_even_without_an_accept_header(): void
    {
        $response = $this->get('/api/me', ['Accept' => 'text/html'])->assertUnauthorized();

        $response->assertJsonPath('message', 'Unauthenticated.');
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }

    public function test_wrong_methods_and_unknown_paths_return_json_errors(): void
    {
        $this->get('/api/login', ['Accept' => 'text/html'])->assertStatus(405)->assertJsonStructure(['message']);
        $this->get('/api/does-not-exist', ['Accept' => 'text/html'])->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_validation_errors_are_json_without_an_accept_header(): void
    {
        $this->post('/api/login', [], ['Accept' => 'text/html'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }
}
