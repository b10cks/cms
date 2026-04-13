<?php

namespace App\Http\Requests\Content;

use App\Models\Space\Block;
use App\Models\Space\Content;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContentTreeOperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $connectionName = new Content()->getConnectionName();

        return [
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.type' => ['required', Rule::in(['create', 'move', 'delete', 'duplicate', 'update_block'])],
            'operations.*.temp_id' => ['sometimes', 'string'],
            'operations.*.id' => [
                'sometimes',
                'string',
                Rule::exists($connectionName . '.contents', 'id')->whereNull('deleted_at'),
            ],
            'operations.*.ids' => ['sometimes', 'array', 'min:1'],
            'operations.*.ids.*' => [
                'string',
                Rule::exists($connectionName . '.contents', 'id')->whereNull('deleted_at'),
            ],
            'operations.*.parent_id' => ['sometimes', 'nullable', 'string'],
            'operations.*.after_id' => ['sometimes', 'nullable', 'string'],
            'operations.*.block_id' => [
                'sometimes',
                'string',
                Rule::exists((new Block())->getConnectionName() . '.blocks', 'id')->whereNull('deleted_at'),
            ],
            'operations.*.name' => ['sometimes', 'required', 'string', 'max:100'],
            'operations.*.slug' => ['sometimes', 'required', 'string', 'max:100'],
            'operations.*.settings' => ['sometimes', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->validated('operations', []) as $index => $operation) {
                    match ($operation['type']) {
                        'create' => $this->ensureHas($operation, $index, ['temp_id', 'block_id', 'name', 'slug']),
                        'move', 'duplicate', 'delete' => $this->ensureHas($operation, $index, ['ids']),
                        'update_block' => $this->ensureHas($operation, $index, ['id', 'block_id']),
                        default => null,
                    };
                }

                $seenTempIds = [];

                foreach ($this->validated('operations', []) as $index => $operation) {
                    foreach (['parent_id', 'after_id'] as $field) {
                        $reference = $operation[$field] ?? null;
                        if ($reference === null) {
                            continue;
                        }

                        $referenceExists = Content::query()
                            ->whereNull('deleted_at')
                            ->whereKey($reference)
                            ->exists();

                        if (! $referenceExists && ! isset($seenTempIds[$reference])) {
                            $validator->errors()->add(
                                "operations.{$index}.{$field}",
                                "The selected {$field} is invalid."
                            );
                        }
                    }

                    if (($operation['type'] ?? null) === 'create' && isset($operation['temp_id'])) {
                        $seenTempIds[$operation['temp_id']] = true;
                    }
                }
            },
        ];
    }

    protected function ensureHas(array $operation, int $index, array $fields): void
    {
        $missing = collect($fields)
            ->reject(fn (string $field) => array_key_exists($field, $operation))
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages(
            $missing
                ->mapWithKeys(fn (string $field) => [
                    "operations.{$index}.{$field}" => "The {$field} field is required for {$operation['type']} operations.",
                ])
                ->all()
        );
    }
}
