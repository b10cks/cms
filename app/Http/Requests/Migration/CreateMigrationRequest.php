<?php

namespace App\Http\Requests\Migration;

use Illuminate\Foundation\Http\FormRequest;

class CreateMigrationRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var \App\Models\Management\Space $space */
        $space = $this->route('space');

        return [
            'target_space_id' => ['required', 'ulid', 'exists:spaces,id', 'not_in:' . $space->id],
            'scope' => ['required', 'array'],
            'scope.blocks' => ['boolean'],
            'scope.block_templates' => ['boolean'],
            'scope.content' => ['boolean'],
            'scope.assets' => ['boolean'],
            'scope.data_sources' => ['boolean'],
            'scope.redirects' => ['boolean'],
            'conflict_strategy' => ['required', 'string', 'in:skip,overwrite,merge_newer'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);

        // Normalise scope defaults to false
        $data['scope'] = array_merge([
            'blocks' => false,
            'block_templates' => false,
            'content' => false,
            'assets' => false,
            'data_sources' => false,
            'redirects' => false,
        ], $data['scope'] ?? []);

        return $data;
    }
}
