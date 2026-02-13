<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\OQLike\Clients\ItopClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ItopClientTest extends TestCase
{
    public function test_basic_auth_mode_sends_basic_and_payload_credentials(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'objects' => [],
            ], 200),
        ]);

        $connection = new Connection([
            'itop_url' => 'https://itop.example.com/webservices/rest.php?version=1.3',
            'auth_mode' => 'basic',
            'username' => 'admin',
            'password_encrypted' => 'secret',
        ]);

        $client = new ItopClient($connection);
        $client->coreGet('cmdbAbstractObject', '1=1', ['id'], 1, 0);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->data()['json_data'] ?? '{}', true);
            $authHeader = $request->header('Authorization')[0] ?? '';

            return ($payload['auth_user'] ?? null) === 'admin'
                && ($payload['auth_pwd'] ?? null) === 'secret'
                && str_starts_with($authHeader, 'Basic ');
        });
    }

    public function test_token_mode_sends_auth_token_in_payload(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'objects' => [],
            ], 200),
        ]);

        $connection = new Connection([
            'itop_url' => 'https://itop.example.com/webservices/rest.php?version=1.3',
            'auth_mode' => 'token',
            'token_encrypted' => 'abcdef',
        ]);

        $client = new ItopClient($connection);
        $client->coreGet('cmdbAbstractObject', '1=1', ['id'], 1, 0);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->data()['json_data'] ?? '{}', true);
            $authHeader = $request->header('Authorization')[0] ?? '';

            return ($payload['auth_token'] ?? null) === 'abcdef'
                && $authHeader === '';
        });
    }
}
