<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\AiChangesetItem;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Run\ToolError;

/** Proposes field changes to one existing entry; validated like a save, stored for review. */
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
            .'Pass the version_id get_entry returned so edits made after you read the entry are detected. Invalid changes come back as errors to fix.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_type' => ['type' => 'string', 'enum' => ChangeTargets::TYPES],
                'id' => ['type' => 'string', 'description' => 'ID of the entry.'],
                'version_id' => ['type' => 'string', 'description' => 'Content only: the version_id you read the entry at.'],
                'changes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'value' => ['description' => 'The new value of the whole field, in the format the field stores.'],
                        ],
                        'required' => ['path', 'value'],
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
        $this->ensureInScope($type, $id);

        $snapshot = $target->find($id, $type === AiChangesetItem::TARGET_CONTENT ? $versionId : null)
            ?? throw new ToolError($versionId
                ? 'No entry with this ID and version. Call get_entry with the ID and pass the version_id it returns.'
                : 'No entry with this ID.');

        $patches = [];
        foreach ($changes as $change) {
            $path = \is_array($change) ? trim((string) ($change['path'] ?? '')) : '';
            if ($path === '' || ! \array_key_exists('value', $change)) {
                throw new ToolError('Every change needs a path and a value.');
            }
            $patches[$path] = $change['value'];
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
        ];
    }
}
