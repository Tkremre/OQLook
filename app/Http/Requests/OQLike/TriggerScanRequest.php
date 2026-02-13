<?php

namespace App\Http\Requests\OQLike;

use Illuminate\Foundation\Http\FormRequest;

class TriggerScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'in:delta,full'],
            'classes' => ['nullable', 'array'],
            'classes.*' => ['string', 'max:255'],
            'thresholdDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'forceSelectedClasses' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('classes') && is_string($this->input('classes'))) {
            $this->merge([
                'classes' => collect(explode(',', (string) $this->input('classes')))
                    ->map(fn (string $className) => trim($className))
                    ->filter()
                    ->values()
                    ->all(),
            ]);
        }

        if ($this->has('forceSelectedClasses')) {
            $this->merge([
                'forceSelectedClasses' => filter_var($this->input('forceSelectedClasses'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }
    }
}
