<?php

namespace App\Http\Requests\OQLike;

use Illuminate\Foundation\Http\FormRequest;

class UpsertConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreateConnection = $this->routeIs('connections.store');

        $usernameRules = ['nullable', 'string', 'max:255'];
        $passwordRules = ['nullable', 'string', 'max:2048'];
        $authTokenRules = ['nullable', 'string', 'max:4096'];

        if ($isCreateConnection) {
            $usernameRules[] = 'required_if:auth_mode,basic';
            $passwordRules[] = 'required_if:auth_mode,basic';
            $authTokenRules[] = 'required_if:auth_mode,token';
        }

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'itop_url' => ['required', 'url', 'max:2048'],
            'auth_mode' => ['required', 'in:basic,token'],
            'username' => $usernameRules,
            'password' => $passwordRules,
            'auth_token' => $authTokenRules,
            'connector_url' => ['nullable', 'url', 'max:2048'],
            'connector_bearer_token' => ['nullable', 'string', 'max:4096'],
            'fallback_classes' => ['nullable', 'array'],
            'fallback_classes.*' => ['string', 'max:255'],
            'mandatory_fields' => ['nullable', 'array'],
            'mandatory_fields.*' => ['string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'name',
            'itop_url',
            'auth_mode',
            'username',
            'password',
            'auth_token',
            'connector_url',
            'connector_bearer_token',
        ] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $normalized = $this->normalizeScalarToString($this->input($field));

            $this->merge([
                $field => $normalized,
            ]);
        }

        if ($this->filled('fallback_classes') && is_string($this->input('fallback_classes'))) {
            $this->merge([
                'fallback_classes' => collect(explode(',', (string) $this->input('fallback_classes')))
                    ->map(fn (string $item) => trim($item))
                    ->filter()
                    ->values()
                    ->all(),
            ]);
        }

        if ($this->filled('mandatory_fields') && is_string($this->input('mandatory_fields'))) {
            $this->merge([
                'mandatory_fields' => collect(explode(',', (string) $this->input('mandatory_fields')))
                    ->map(fn (string $item) => trim($item))
                    ->filter()
                    ->values()
                    ->all(),
            ]);
        }
    }

    private function normalizeScalarToString(mixed $value): mixed
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
