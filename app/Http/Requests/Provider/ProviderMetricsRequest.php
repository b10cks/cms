<?php

namespace App\Http\Requests\Provider;

use App\Services\Provider\ProviderMetricsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_root;
    }

    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'string', Rule::in(array_keys(ProviderMetricsService::RANGES))],
        ];
    }

    public function range(): string
    {
        return $this->string('range', '12m')->toString();
    }
}
