<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Ai\Changesets\BlockTarget;
use App\Services\Ai\Run\ToolError;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentI18nValidator;
use App\Services\Content\Schema\ContentSchemaValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Proposes a new content entry or block; validated like a create, stored for review. */
class ProposeCreateTool extends ProposalTool
{
    public const NAME = 'propose_create';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Proposes a new content entry, or with target_type block a new block definition. Nothing is created until an editor accepts it. '
            .'Content: use a block slug from get_block_list and the field keys of its schema; nested blocks need an `id` (any unique string) and a `block` slug. '
            .'Block: name, slug (starts with a lowercase letter, letters and numbers only, unique), type (root, nestable, single or universal), optional description, icon, color (#rrggbb) and schema, '
            .'an object of field definitions by field key, each with at least `type` and `name`. Read an existing block with get_entry to see the format.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_type' => ['type' => 'string', 'enum' => [AiChangesetItem::TARGET_CONTENT, AiChangesetItem::TARGET_BLOCK], 'description' => 'Defaults to content.'],
                'block' => ['type' => 'string', 'description' => 'Content: block slug of the new entry.'],
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'description' => 'Content: optional, derived from the name when omitted. Block: required.'],
                'parent_id' => ['type' => 'string', 'description' => 'Content: optional folder or parent entry ID.'],
                'content' => ['type' => 'object', 'description' => 'Content: field values keyed by field key.'],
                'type' => ['type' => 'string', 'enum' => ['root', 'nestable', 'single', 'universal'], 'description' => 'Block: where the block can be used.'],
                'description' => ['type' => 'string', 'description' => 'Block: optional.'],
                'icon' => ['type' => 'string', 'description' => 'Block: optional icon name.'],
                'color' => ['type' => 'string', 'description' => 'Block: optional #rrggbb color.'],
                'schema' => ['type' => 'object', 'description' => 'Block: field definitions keyed by field key.'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $input): mixed
    {
        $type = (string) ($input['target_type'] ?? AiChangesetItem::TARGET_CONTENT);

        return match ($type) {
            AiChangesetItem::TARGET_CONTENT => $this->content($input),
            AiChangesetItem::TARGET_BLOCK => $this->block($input),
            default => throw new ToolError('target_type must be content or block.'),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function block(array $input): mixed
    {
        $target = $this->target(AiChangesetItem::TARGET_BLOCK);
        \assert($target instanceof BlockTarget);
        if (! $this->canEdit($target)) {
            throw new ToolError('You may not create blocks in this space.');
        }
        $this->ensureInScope(AiChangesetItem::TARGET_BLOCK, null);

        $data = $target->validateNew([
            ...array_map(fn ($value) => \is_string($value) ? trim($value) : $value, array_intersect_key($input, array_flip(['name', 'slug', 'type', 'description', 'icon', 'color']))),
            'schema' => \is_array($input['schema'] ?? null) ? $input['schema'] : [],
        ]);

        [$item] = $this->record([[
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_BLOCK,
            'target_label' => (string) $data['name'],
            'after' => $data,
        ]]);

        return ['proposed' => 1, 'item_ids' => [$item->id]];
    }

    /** @param  array<string, mixed>  $input */
    private function content(array $input): mixed
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
