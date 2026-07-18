<?php

namespace App\Http\Requests\Block;

use App\Models\Space\BlockFolder;
use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\BlockSchemaRequestValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncBlocksRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization will be handled by policies/middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'blocks' => 'present|array',
            'blocks.*.external_id' => 'required|string|max:36',
            'blocks.*.name' => 'required|string|max:100',
            'blocks.*.slug' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9A-Z]+$/',
            ],
            'blocks.*.icon' => 'nullable|string|max:50',
            'blocks.*.color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'blocks.*.description' => 'nullable|string',
            'blocks.*.type' => 'required|string|in:root,nestable,single,universal',
            'blocks.*.preview_template' => 'nullable|string',
            'blocks.*.preview_file' => 'nullable|string|max:255',
            'blocks.*.schema' => 'nullable|array',
            'blocks.*.editor' => 'nullable|array',
            'blocks.*.tags' => 'nullable|array',
            'blocks.*.folder_id' => [
                'nullable',
                'string',
                Rule::exists(new BlockFolder()->getConnectionName() . '.block_folders', 'id'),
            ],
            'prune' => 'boolean',
            'dry_run' => 'boolean',
            'commit_message' => 'nullable|string|max:500',
        ];
    }

    protected function prepareForValidation(): void
    {
        $blocks = $this->input('blocks');

        if (!is_array($blocks)) {
            return;
        }

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            if (is_array($block['schema'] ?? null)) {
                $blocks[$index]['schema'] = BlockSchema::fromArray($block['schema'])->toArray();
            }

            if (is_array($block['editor'] ?? null)) {
                $blocks[$index]['editor'] = array_values($block['editor']);
            }
        }

        $this->merge(['blocks' => array_values($blocks)]);
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $blocks = $this->input('blocks', []);

                if (!is_array($blocks)) {
                    return;
                }

                $externalIds = [];
                $slugs = [];

                foreach ($blocks as $index => $block) {
                    if (!is_array($block)) {
                        continue;
                    }

                    $externalId = $block['external_id'] ?? null;
                    if ($externalId !== null && isset($externalIds[$externalId])) {
                        $validator->errors()->add(
                            "blocks.{$index}.external_id",
                            "Duplicate external_id '{$externalId}' in payload."
                        );
                    }
                    $externalIds[$externalId] = true;

                    $slug = $block['slug'] ?? null;
                    if ($slug !== null && isset($slugs[$slug])) {
                        $validator->errors()->add(
                            "blocks.{$index}.slug",
                            "Duplicate slug '{$slug}' in payload."
                        );
                    }
                    $slugs[$slug] = true;

                    $errors = app(BlockSchemaRequestValidator::class)->validate(
                        $block['schema'] ?? [],
                        $block['editor'] ?? [],
                    );

                    foreach ($errors as $path => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add("blocks.{$index}.{$path}", $message);
                        }
                    }
                }
            },
        ];
    }
}
