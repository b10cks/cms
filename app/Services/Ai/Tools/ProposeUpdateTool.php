<?php

namespace App\Services\Ai\Tools;

use App\Models\Space\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Ai\Changesets\BlockTarget;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\ContentTarget;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Changesets\TargetSnapshot;
use App\Services\Ai\Run\ToolError;
use Illuminate\Support\Str;

/**
 * Proposes changes to one existing entry; validated like a save, stored for
 * review. Besides setting fields, a change can insert or remove a block in a
 * block list. All changes to one block list become a single item for the whole
 * list, so accepting one part never makes the rest conflict.
 */
class ProposeUpdateTool extends ProposalTool
{
    public const NAME = 'propose_update';

    private const MAX_CHANGES = 50;

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Proposes changes to fields of one entry. Nothing is saved: an editor reviews and accepts each change. '
            .'Content paths are field keys of the block schema; a field in a nested block is <field>.<block id>.<field>, using the block IDs from get_entry, never array positions. '
            .'Data entry paths are `value` or `dimensions.<key>`; asset paths are `fields.<locale>.<field>`. Each change replaces the whole field value. '
            .'Block definitions (target_type block, id = block ID or slug): paths `name`, `description`, `icon`, `color` and `schema.<field key>`, whose value is the whole field definition (type, name and settings). A new key adds a field, an existing key edits its settings. '
            .'Removing a field, renaming a field key, changing a field\'s type and changing the block\'s slug or type are refused: content is not migrated, so leave those to a person in the block editor. '
            .'Content only: to add a block to a block list, pass {path: <block list field>, insert: {block: <slug>, values: {<field>: <value>}}, after: <block id> or "start"} (without after it goes last); '
            .'to remove one, pass {path: <block list field>, remove: <block id>}. Find slugs and their fields with get_block_list and get_block_schemas. '
            .'Pass the version_id get_entry returned so edits made after you read the entry are detected. Invalid changes come back as errors to fix.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_type' => ['type' => 'string', 'enum' => ChangeTargets::PROPOSAL_TYPES],
                'id' => ['type' => 'string', 'description' => 'ID of the entry; a block also by slug.'],
                'version_id' => ['type' => 'string', 'description' => 'Content only: the version_id you read the entry at.'],
                'changes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'value' => ['description' => 'The new value of the whole field, in the format the field stores.'],
                            'insert' => [
                                'type' => 'object',
                                'description' => 'Content only: a new block for the block list at path.',
                                'properties' => [
                                    'block' => ['type' => 'string', 'description' => 'Slug of the block.'],
                                    'values' => ['type' => 'object', 'description' => 'Field values of the new block, by field key.'],
                                ],
                                'required' => ['block'],
                            ],
                            'after' => ['type' => 'string', 'description' => 'With insert: the block id to insert after, or "start". Omit to append.'],
                            'remove' => ['type' => 'string', 'description' => 'Content only: id of the block to remove from the list at path.'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
            'required' => ['target_type', 'id', 'changes'],
        ];
    }

    public function execute(array $input): mixed
    {
        $type = (string) ($input['target_type'] ?? '');
        $id = (string) ($input['id'] ?? '');
        $versionId = \is_string($input['version_id'] ?? null) && $input['version_id'] !== '' ? $input['version_id'] : null;
        $changes = \is_array($input['changes'] ?? null) ? array_values($input['changes']) : [];

        if ($changes === [] || \count($changes) > self::MAX_CHANGES) {
            throw new ToolError('Pass between 1 and '.self::MAX_CHANGES.' changes.');
        }

        $target = $this->target($type);
        $snapshot = $target->find($id, $type === AiChangesetItem::TARGET_CONTENT ? $versionId : null)
            ?? throw new ToolError($versionId
                ? 'No entry with this ID and version. Call get_entry with the ID and pass the version_id it returns.'
                : 'No entry with this ID.');
        // A block may be named by its slug.
        $id = (string) $snapshot->model->getKey();
        $this->ensureInScope($type, $id);

        [$patches, $inserted] = $this->patches($type, $snapshot, $changes);
        if ($target instanceof BlockTarget) {
            $patches = $target->normalize($patches);
        }

        $types = $target->validate($this->space, $snapshot, $patches);

        $items = $this->record(array_map(fn (string $path) => [
            'operation' => AiChangesetItem::OPERATION_UPDATE,
            'target_type' => $type,
            'target_id' => $id,
            'target_label' => $snapshot->label,
            'base_version_id' => $snapshot->versionId,
            'path' => $path,
            'field_type' => $types[$path] ?? null,
            'before' => FieldPath::get($snapshot->document, $path),
            'after' => $patches[$path],
        ], array_map('strval', array_keys($patches))));

        return [
            'proposed' => \count($items),
            'item_ids' => array_map(fn (AiChangesetItem $item) => $item->id, $items),
            ...($inserted !== [] ? ['inserted_blocks' => $inserted] : []),
        ];
    }

    /**
     * Field patches by path. Block inserts and removals rewrite their whole
     * list; field changes inside such a list fold into it.
     *
     * @param  list<mixed>  $changes
     * @return array{0: array<string, mixed>, 1: list<array{id: string, block: string, path: string}>}
     */
    private function patches(string $type, TargetSnapshot $snapshot, array $changes): array
    {
        $document = $snapshot->document;
        $patches = [];
        $lists = [];
        $inserted = [];

        foreach ($changes as $change) {
            $path = \is_array($change) ? trim((string) ($change['path'] ?? '')) : '';
            $structural = \is_array($change) && (isset($change['insert']) || isset($change['remove']));

            if ($path === '' || (! $structural && ! \array_key_exists('value', $change))) {
                throw new ToolError('Every change needs a path and a value, an insert or a remove.');
            }

            if (! $structural) {
                $patches[$path] = $change['value'];
                $document = FieldPath::set($document, $path, $change['value']);

                continue;
            }

            if ($type !== AiChangesetItem::TARGET_CONTENT) {
                throw new ToolError('Blocks can only be inserted into or removed from content entries.');
            }

            /** @var Content $content */
            $content = $snapshot->model;
            $field = app(ContentTarget::class)->fieldAt($content->block ?? throw new ToolError('This entry has no block.'), $document, $path);
            if ($field['type'] !== 'blocks') {
                throw new ToolError("\"{$path}\" is not a block list.");
            }

            $items = array_values(\is_array(FieldPath::get($document, $path)) ? FieldPath::get($document, $path) : []);
            $items = isset($change['insert'])
                ? $this->insert($items, $change, $path, $inserted)
                : $this->remove($items, (string) $change['remove'], $path);

            $document = FieldPath::set($document, $path, $items);
            $lists[] = $path;
        }

        foreach (array_unique($lists) as $list) {
            $patches = array_filter($patches, fn (string $path) => $path !== $list && ! str_starts_with($path, "{$list}."), ARRAY_FILTER_USE_KEY);
            $patches[$list] = FieldPath::get($document, $list);
        }

        return [$patches, $inserted];
    }

    /**
     * @param  list<mixed>  $items
     * @param  array<string, mixed>  $change
     * @param  list<array{id: string, block: string, path: string}>  $inserted
     * @return list<mixed>
     */
    private function insert(array $items, array $change, string $path, array &$inserted): array
    {
        $insert = \is_array($change['insert']) ? $change['insert'] : [];
        $slug = trim((string) ($insert['block'] ?? ''));
        if ($slug === '') {
            throw new ToolError("{$path}: an insert needs the block slug.");
        }

        $values = \is_array($insert['values'] ?? null) ? $insert['values'] : [];
        unset($values['id'], $values['block']);

        // Unknown keys would be dropped on save without a word.
        $keys = array_keys(Block::query()->where('slug', $slug)->first()?->schema?->toArray() ?? []);
        $unknown = $keys === [] ? [] : array_diff(array_keys($values), $keys);
        if ($unknown !== []) {
            throw new ToolError("{$path}: \"{$slug}\" has no field ".implode(', ', $unknown).'. Its fields: '.implode(', ', $keys).'.');
        }
        $id = strtolower((string) Str::ulid());
        $item = ['id' => $id, 'block' => $slug, ...$values];

        $after = $change['after'] ?? null;
        $index = match (true) {
            $after === null || $after === '' => \count($items),
            $after === 'start' => 0,
            default => $this->indexOf($items, (string) $after, $path) + 1,
        };

        array_splice($items, $index, 0, [$item]);
        $inserted[] = ['id' => $id, 'block' => $slug, 'path' => $path];

        return $items;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private function remove(array $items, string $id, string $path): array
    {
        array_splice($items, $this->indexOf($items, $id, $path), 1);

        return $items;
    }

    /** @param  list<mixed>  $items */
    private function indexOf(array $items, string $id, string $path): int
    {
        foreach ($items as $index => $item) {
            if (\is_array($item) && ($item['id'] ?? null) === $id) {
                return $index;
            }
        }

        throw new ToolError("{$path}: no block with id \"{$id}\". Use the block IDs from get_entry.");
    }
}
