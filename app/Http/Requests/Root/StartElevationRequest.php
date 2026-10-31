<?php

namespace App\Http\Requests\Root;

use App\Models\Management\RootElevation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartElevationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_root;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'duration' => ['sometimes', 'integer', Rule::in(RootElevation::DURATIONS)],
        ];
    }

    public function minutes(): int
    {
        return $this->integer('duration', RootElevation::DEFAULT_DURATION);
    }
}
