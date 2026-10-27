<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Ai\Run\ToolError;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentI18nValidator;
use App\Services\Content\Schema\ContentSchemaValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Proposes a new content entry; validated like a create, stored for review. */
class ProposeCreateTool extends ProposalTool
{
    public const NAME = 'propose_create';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Proposes a new content entry. Nothing is created until an editor accepts it. '
            .'Use a block slug from get_block_list and the field keys of its schema; nested blocks need an `id` (any unique string) and a `block` slug.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block' => ['type' => 'string', 'description' => 'Block slug of the new entry.'],
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'description' => 'Optional; derived from the name when omitted.'],
                'parent_id' => ['type' => 'string', 'description' => 'Optional folder or parent entry ID.'],
                'content' => ['type' => 'object', 'description' => 'Field values keyed by field key.'],
            ],
            'required' => ['block', 'name', 'content'],
        ];
    }

    public function execute(array $input): mixed
    {
        $this->target(AiChangesetItem::TARGET_CONTENT);
        $this->ensureInScope(AiChangesetItem::TARGET_CONTENT, null);

        $name = trim((string) ($input['name'] ?? ''));
        $block = Block::query()->where('slug', (string) ($input['block'] ?? ''))->first();
        $parentId = \is_string($input['parent_id'] ?? null) && $input['parent_id'] !== '' ? $input['parent_id'] : null;
        $content = \is_array($input['content'] ?? null) ? $input['content'] : [];

        if ($name === '' || ! $block) {
            throw new ToolError($name === '' ? 'Give the entry a name.' : 'Unknown block. Use a slug from get_block_list.');
        }

        $data = [
            'block_id' => $block->id,
            'name' => $name,
            'slug' => Str::slug((string) ($input['slug'] ?? '') ?: $name),
            'parent_id' => $parentId,
            'language_iso' => $this->space->settings->getDefaultLanguage(),
            'content' => $content,
        ];

        $problems = app(ContentI18nValidator::class)->validate($this->space, $data);

        try {
            $parent = $parentId !== null ? Content::query()->with('block')->find($parentId) : null;
            app(ContentHierarchyValidator::class)->validatePlacement($this->space, $block, $parent, null, $data['language_iso']);
        } catch (ValidationException $e) {
            $problems = [...$problems, ...array_map(fn (array $messages) => implode(' ', $messages), $e->errors())];
        }

        $schema = app(ContentSchemaValidator::class)->validateSubmission($this->space, $block, $content, null, $data['language_iso'], null, 'save');
        foreach ($schema->errors as $key => $messages) {
            $problems[$key] = implode(' ', (array) $messages);
        }

        if ($problems !== []) {
            throw new ToolError("The entry is invalid:\n- ".implode("\n- ", array_map(
                fn ($key, $message) => "{$key}: ".(\is_array($message) ? implode(' ', $message) : $message),
                array_keys($problems),
                $problems,
            )));
        }

        [$item] = $this->record([[
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_CONTENT,
            'target_label' => $name,
            'after' => [...$data, 'content' => $schema->content],
        ]]);

        return ['proposed' => 1, 'item_ids' => [$item->id]];
    }
}
