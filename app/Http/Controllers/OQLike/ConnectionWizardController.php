<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Http\Requests\OQLike\UpsertConnectionRequest;
use App\Models\Connection;
use App\OQLike\Clients\ConnectorClient;
use App\OQLike\Clients\ItopClient;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionWizardController extends Controller
{
    public function index(): Response
    {
        $connections = Connection::query()
            ->latest('id')
            ->get([
                'id',
                'name',
                'itop_url',
                'auth_mode',
                'username',
                'connector_url',
                'fallback_config_json',
                'last_scan_time',
            ])
            ->map(function (Connection $connection): array {
                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'itop_url' => $connection->itop_url,
                    'auth_mode' => $connection->auth_mode,
                    'username' => $connection->username,
                    'connector_url' => $connection->connector_url,
                    'fallback_config_json' => $connection->fallback_config_json ?? [],
                    'last_scan_time' => $connection->last_scan_time,
                    'has_password' => $connection->getRawOriginal('password_encrypted') !== null && $connection->getRawOriginal('password_encrypted') !== '',
                    'has_token' => $connection->getRawOriginal('token_encrypted') !== null && $connection->getRawOriginal('token_encrypted') !== '',
                    'has_connector_bearer' => $connection->getRawOriginal('connector_bearer_encrypted') !== null && $connection->getRawOriginal('connector_bearer_encrypted') !== '',
                ];
            });

        return Inertia::render('Connections/Wizard', [
            'connections' => $connections,
        ]);
    }

    public function store(UpsertConnectionRequest $request)
    {
        $payload = $request->validated();

        $connection = Connection::create([
            'name' => $payload['name'] ?? 'Default iTop',
            'itop_url' => $payload['itop_url'],
            'auth_mode' => $payload['auth_mode'],
            'username' => $payload['auth_mode'] === 'basic' ? ($payload['username'] ?? null) : null,
            'password_encrypted' => $payload['auth_mode'] === 'basic' ? ($payload['password'] ?? null) : null,
            'token_encrypted' => $payload['auth_mode'] === 'token' ? ($payload['auth_token'] ?? null) : null,
            'connector_url' => $payload['connector_url'] ?? null,
            'connector_bearer_encrypted' => $payload['connector_bearer_token'] ?? null,
            'fallback_config_json' => [
                'classes' => $payload['fallback_classes'] ?? [],
                'mandatory_fields' => $payload['mandatory_fields'] ?? ['name'],
            ],
        ]);

        return redirect()->route('connections.wizard')->with('status', sprintf('Connexion %s créée.', $connection->name));
    }

    public function update(UpsertConnectionRequest $request, Connection $connection)
    {
        $payload = $request->validated();
        $fallbackConfig = $connection->fallback_config_json ?? [];

        $connection->fill([
            'name' => $payload['name'] ?? $connection->name,
            'itop_url' => $payload['itop_url'] ?? $connection->itop_url,
            'auth_mode' => $payload['auth_mode'],
            'username' => $payload['auth_mode'] === 'basic'
                ? (($payload['username'] ?? $connection->username) ?: $connection->username)
                : null,
            'password_encrypted' => $payload['auth_mode'] === 'token' ? null : $connection->password_encrypted,
            'token_encrypted' => $payload['auth_mode'] === 'basic' ? null : $connection->token_encrypted,
            'connector_url' => array_key_exists('connector_url', $payload)
                ? ($payload['connector_url'] ?: null)
                : $connection->connector_url,
            'fallback_config_json' => [
                'classes' => is_array($payload['fallback_classes'] ?? null)
                    ? $payload['fallback_classes']
                    : Arr::get($fallbackConfig, 'classes', []),
                'mandatory_fields' => is_array($payload['mandatory_fields'] ?? null)
                    ? $payload['mandatory_fields']
                    : Arr::get($fallbackConfig, 'mandatory_fields', ['name']),
            ],
        ]);

        if ($payload['auth_mode'] === 'basic' && ! empty($payload['password'])) {
            $connection->password_encrypted = $payload['password'];
        }

        if ($payload['auth_mode'] === 'token' && ! empty($payload['auth_token'])) {
            $connection->token_encrypted = $payload['auth_token'];
        }

        if (! empty($payload['connector_bearer_token'])) {
            $connection->connector_bearer_encrypted = $payload['connector_bearer_token'];
        }

        $connection->save();

        return redirect()->route('connections.wizard')->with('status', sprintf('Connexion %s mise à jour.', $connection->name));
    }

    public function testItop(Connection $connection)
    {
        return response()->json([
            ...((new ItopClient($connection))->testConnectivity()),
            'tested_at' => now()->toIso8601String(),
            'connection_id' => $connection->id,
            'connection_name' => $connection->name,
        ]);
    }

    public function testConnector(Connection $connection)
    {
        return response()->json([
            ...((new ConnectorClient($connection))->testConnectivity()),
            'tested_at' => now()->toIso8601String(),
            'connection_id' => $connection->id,
            'connection_name' => $connection->name,
        ]);
    }

    public function testDraftItop(Request $request)
    {
        $payload = $this->normalizeDraftPayload($request->all());
        $validator = Validator::make($payload, [
            'itop_url' => ['required', 'url', 'max:2048'],
            'auth_mode' => ['required', 'in:basic,token'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2048'],
            'auth_token' => ['nullable', 'string', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => (string) $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
                'draft' => true,
            ]);
        }

        $connection = new Connection([
            'itop_url' => $payload['itop_url'],
            'auth_mode' => $payload['auth_mode'],
            'username' => $payload['username'] ?? null,
            'password_encrypted' => $payload['password'] ?? null,
            'token_encrypted' => $payload['auth_token'] ?? null,
        ]);

        return response()->json([
            ...((new ItopClient($connection))->testConnectivity()),
            'tested_at' => now()->toIso8601String(),
            'draft' => true,
        ]);
    }

    public function testDraftConnector(Request $request)
    {
        $payload = $this->normalizeDraftPayload($request->all());
        $validator = Validator::make($payload, [
            'connector_url' => ['required', 'url', 'max:2048'],
            'connector_bearer_token' => ['nullable', 'string', 'max:4096'],
            'auth_mode' => ['nullable', 'in:basic,token'],
            'itop_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => (string) $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
                'draft' => true,
            ]);
        }

        $connection = new Connection([
            'connector_url' => $payload['connector_url'] ?? null,
            'connector_bearer_encrypted' => $payload['connector_bearer_token'] ?? null,
            'auth_mode' => $payload['auth_mode'] ?? 'basic',
            'itop_url' => $payload['itop_url'] ?? '',
        ]);

        return response()->json([
            ...((new ConnectorClient($connection))->testConnectivity()),
            'tested_at' => now()->toIso8601String(),
            'draft' => true,
        ]);
    }

    private function normalizeDraftPayload(array $input): array
    {
        return [
            'name' => $this->normalizeScalarToString($input['name'] ?? null),
            'itop_url' => $this->normalizeScalarToString($input['itop_url'] ?? null),
            'auth_mode' => $this->normalizeScalarToString($input['auth_mode'] ?? null),
            'username' => $this->normalizeScalarToString($input['username'] ?? null),
            'password' => $this->normalizeScalarToString($input['password'] ?? null),
            'auth_token' => $this->normalizeScalarToString($input['auth_token'] ?? null),
            'connector_url' => $this->normalizeScalarToString($input['connector_url'] ?? null),
            'connector_bearer_token' => $this->normalizeScalarToString($input['connector_bearer_token'] ?? null),
        ];
    }

    private function normalizeScalarToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item) || is_bool($item)) {
                    return trim((string) $item);
                }
            }
        }

        return null;
    }
}
