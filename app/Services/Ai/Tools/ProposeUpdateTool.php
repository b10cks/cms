<?php

namespace App\Services\Ai\Tools;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperationItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Ai\Changesets\BlockTarget;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\ContentTarget;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Changesets\TargetSnapshot;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Metadata\MetadataOutputs;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use Illuminate\Support\Str;

/**
 * Changes one existing entry, validated like a save. Content goes straight into
 * the entry's draft under the run's actor; a field someone changed since the agent
 * read it stays open as a conflict for a person, the agent is told, and further
 * writes to that field are refused until a person decides. Other targets wait as
 * proposals for a person. Besides setting fields, a change can insert or remove a
 * block in a block list. All changes to one block list become a single item for
 * the whole list.
 *
 * A `meta` field is one object in the document, so a change to it is merged
 * over the stored object and this task's own earlier value ({@see MetadataOutputs}):
 * keys it does not name, such as canonical, robots and ogImage, stay. Inside an
 * operation code enforces the approved keys and overwrites, and an entry under
 * a metadata plan accounts for each key on its own.
 */
class ProposeUpdateTool extends ProposalTool implements ReportsOutcomes
{
    public const NAME = 'propose_update';

    private const MAX_CHANGES = 50;

    /** @var list<array{target_type: string, target_id: string, outcome: string, reason: string|null, part?: string, note?: string}> */
    private array $outcomes = [];

    public function takeOutcomes(): array
    {
        [$outcomes, $this->outcomes] = [$this->outcomes, []];

        return $outcomes;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Changes fields of one entry. Content is written to the entry\'s draft right away; nothing is published. Asset, data entry and block changes wait as proposals for an editor to accept. '
            .'Content paths are field keys of the block schema; a field in a nested block is <field>.<block id>.<field>, using the block IDs from get_entry, never array positions. '
            .'Data entry paths are `value` or `dimensions.<key>`; asset paths are `fields.<locale>.<field>`. Each change replaces the whole field value. '
            .'Block definitions (target_type block, id = block ID or slug): paths `name`, `description`, `icon`, `color` and `schema.<field key>`, whose value is the whole field definition (type, name and settings). A new key adds a field, an existing key edits its settings. '
            .'Removing a field, renaming a field key, changing a field\'s type and changing the block\'s slug or type are refused: content is not migrated, so leave those to a person in the block editor. '
            .'Content only: to add a block to a block list, pass {path: <block list field>, insert: {block: <slug>, values: {<field>: <value>}}, after: <block id> or "start"} (without after it goes last); '
            .'to remove one, pass {path: <block list field>, remove: <block id>}. Find slugs and their fields with get_block_list and get_block_schemas. '
            .'Content needs the version_id get_entry returned: a field a person changed after you read it is not written and comes back under `conflicts`. It stays open for that person: do not write it again, and mention it in your summary. The other fields are written. The result holds the new version_id for further changes. Invalid changes come back as errors to fix. '
            .'A metadata field keeps every key you do not name, so pass only the keys you write. '
            .'Banned wording or a changed protected name comes back under `glossary_findings`, and wording the glossary requires but your text lacks under `glossary_hints`. Read the note in the result.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_type' => ['type' => 'string', 'enum' => ChangeTargets::PROPOSAL_TYPES],
                'id' => ['type' => 'string', 'description' => 'ID of the entry; a block also by slug.'],
                'version_id' => ['type' => 'string', 'description' => 'Content: required, the version_id you read the entry at.'],
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
        if ($type === AiChangesetItem::TARGET_CONTENT) {
            $this->ensureCanWriteContent();
            if ($versionId === null) {
                throw new ToolError('Pass the version_id get_entry returned, so nothing a person changed since is overwritten.');
            }
        }
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

        $this->outcomes = [];
        $metadata = null;
        if ($snapshot->model instanceof Content) {
            [$patches, $metadata] = $this->metadataPatches($snapshot, $id, $patches);
        }

        $left = $type === AiChangesetItem::TARGET_CONTENT ? $this->leftForPerson($id, array_map('strval', array_keys($patches))) : [];
        $patches = array_diff_key($patches, $left);

        if ($patches === [] && ($metadata !== null || $left !== [])) {
            // Everything named was kept or refused: the result says what is still owed.
            if ($metadata !== null) {
                $this->closeMetadata($id, $metadata, array_keys($left));
            } else {
                $this->outcomes[] = ['target_type' => $type, 'target_id' => $id, 'outcome' => self::CONFLICT, 'reason' => null];
            }

            return [
                ...($left !== [] ? $this->writeResult(['written' => collect(), 'conflicts' => collect(), 'version_id' => $snapshot->versionId], $left) : ['written' => 0, 'item_ids' => []]),
                ...($metadata !== null ? ['metadata' => $this->withoutConflicted($metadata, array_keys($left))] : []),
            ];
        }

        $types = $target->validate($this->space, $snapshot, $patches);

        $record = fn () => $this->record(array_map(fn (string $path) => [
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

        if ($type === AiChangesetItem::TARGET_CONTENT) {
            $write = $this->writeContent($record);
            if ($metadata !== null) {
                $conflicted = [...$write['conflicts']->pluck('path')->all(), ...array_keys($left)];
                $this->closeMetadata($id, $metadata, $conflicted);
                $metadata = $this->withoutConflicted($metadata, $conflicted);
            } else {
                // An entry that is one piece settles here. One worked in parts ignores a claim about the whole.
                $this->outcomes[] = ['target_type' => $type, 'target_id' => $id, 'outcome' => $write['conflicts']->isEmpty() && $left === [] ? self::WRITTEN : self::CONFLICT, 'reason' => null];
            }

            return [
                ...$this->writeResult($write, $left),
                ...($inserted !== [] ? ['inserted_blocks' => $inserted] : []),
                ...($metadata !== null ? ['metadata' => $metadata] : []),
            ];
        }

        $items = $record();

        return [
            'proposed' => \count($items),
            'item_ids' => array_map(fn (AiChangesetItem $item) => $item->id, $items),
            ...$this->findingsResult($items, written: false),
            ...($inserted !== [] ? ['inserted_blocks' => $inserted] : []),
        ];
    }

    /**
     * Merge changes to `meta` fields over what is stored and this task's own, under
     * the run's policy. An entry that owes parts takes what is valid and has
     * the rest reported; any other run gets refused as a whole, to fix.
     *
     * @param  array<string, mixed>  $patches
     * @return array{0: array<string, mixed>, 1: array{written: list<string>, kept: list<string>, invalid: array<string, string>, owed: list<string>}|null}
     *
     * @throws ToolError
     */
    private function metadataPatches(TargetSnapshot $snapshot, string $id, array $patches): array
    {
        /** @var Content $content */
        $content = $snapshot->model;
        $block = $content->block;
        $context = OperationContext::forRun($this->run);
        $batch = $context !== null && ! $context->isPlanning();
        $owing = $batch ? $context->owing(AiChangesetItem::TARGET_CONTENT, $id) : null;
        $plan = $batch ? ($context->plan()['metadata'] ?? null) : null;
        $locale = $content->language_iso ?: $this->space->settings->getDefaultLanguage();
        $document = $snapshot->document;
        foreach ($patches as $path => $value) {
            $document = FieldPath::set($document, (string) $path, $value);
        }

        $report = $owing !== null ? ['written' => [], 'kept' => [], 'invalid' => [], 'owed' => []] : null;
        $merged = [];

        foreach ($patches as $path => $value) {
            $path = (string) $path;
            $isMeta = $block !== null && app(ContentTarget::class)->fieldAt($block, $document, $path)['type'] === 'meta';

            if ($owing !== null && (! $isMeta || ! \in_array($path, $this->owedFields($owing, $locale), true))) {
                throw new ToolError("{$path}: the approved plan covers the metadata it names, and nothing else on this entry.");
            }
            if (! $isMeta) {
                $merged[$path] = $value;

                continue;
            }

            $stored = FieldPath::get($snapshot->document, $path);
            $stored = \is_array($stored) ? $stored : [];
            $generated = $value;
            if ($owing !== null && \is_array($generated)) {
                foreach (array_keys($generated) as $key) {
                    if ($owing->settledEarlier("{$locale}.{$path}.{$key}")) {
                        unset($generated[$key]);
                        // Reporting a blocked or otherwise settled key as kept would settle it again.
                        if ($owing->deliveredEarlier("{$locale}.{$path}.{$key}")) {
                            $report['kept'][] = "{$locale}.{$path}.{$key}";
                        }
                    }
                }
            }

            $writable = $batch ? ($plan['keys'] ?? MetadataOutputs::KEYS) : null;
            $result = MetadataOutputs::apply($stored, $this->ownValue($id, $path) ?? $stored, $generated, $writable, $plan['overwrite_keys'] ?? []);

            if ($owing === null) {
                if ($result['invalid'] !== []) {
                    throw new ToolError(collect($result['invalid'])->map(fn (string $problem, string $key) => "{$path}.{$key} {$problem}")->implode(' '));
                }
                if ($batch && $result['written'] === []) {
                    throw new ToolError("{$path} needs no change: its values stay unless the approved plan allows overwriting them. Report the entry instead.");
                }
                $merged[$path] = $result['value'];

                continue;
            }

            foreach ($result['written'] as $key) {
                $report['written'][] = "{$locale}.{$path}.{$key}";
            }
            foreach ($result['kept'] as $key) {
                $report['kept'][] = "{$locale}.{$path}.{$key}";
            }
            foreach ($result['invalid'] as $key => $problem) {
                $report['invalid'][$key === '*' ? "{$locale}.{$path}" : "{$locale}.{$path}.{$key}"] = "{$path}.{$key} {$problem}";
            }
            if ($result['written'] !== []) {
                $merged[$path] = $result['value'];
            }
        }

        if ($report !== null) {
            $report['owed'] = array_values(array_diff($owing->openParts(null), $report['written'], $report['kept']));
        }

        return [$merged, $report];
    }

    /**
     * Report each key of a metadata write on the entry.
     *
     * @param  array{written: list<string>, kept: list<string>, invalid: array<string, string>, owed: list<string>}  $report
     * @param  list<string|null>  $conflicted  meta fields that were not written
     */
    private function closeMetadata(string $id, array $report, array $conflicted = []): void
    {
        $outcome = fn (string $part, string $outcome, ?string $reason, ?string $note = null) => $this->outcomes[] = array_filter(
            ['target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $id, 'outcome' => $outcome, 'part' => $part, 'note' => $note],
            fn ($value) => $value !== null,
        ) + ['reason' => $reason];

        foreach ($report['written'] as $part) {
            $hit = array_filter($conflicted, fn (?string $path) => $path !== null && str_contains($part, ".{$path}."));
            $outcome($part, $hit !== [] ? self::CONFLICT : self::WRITTEN, null);
        }
        foreach ($report['kept'] as $part) {
            $outcome($part, self::SATISFIED, 'has_value');
        }
        foreach ($report['invalid'] as $part => $problem) {
            $outcome((string) $part, self::INVALID, 'invalid', $problem);
        }
    }

    /**
     * The metadata report without the parts of fields a person holds: they were not written.
     *
     * @param  array{written: list<string>, kept: list<string>, invalid: array<string, string>, owed: list<string>}  $report
     * @param  list<string|null>  $conflicted
     * @return array{written: list<string>, kept: list<string>, invalid: array<string, string>, owed: list<string>}
     */
    private function withoutConflicted(array $report, array $conflicted): array
    {
        $report['written'] = array_values(array_filter($report['written'], fn (string $part) => array_filter(
            $conflicted,
            fn (?string $path) => $path !== null && str_contains($part, ".{$path}."),
        ) === []));

        return $report;
    }

    /**
     * Meta fields a metadata entry owes keys of.
     *
     * @return list<string>
     */
    private function owedFields(AiOperationItem $owing, string $locale): array
    {
        return array_values(array_unique(array_map(
            fn (string $part) => Str::beforeLast(Str::after($part, "{$locale}."), '.'),
            $owing->detail['expected_parts'],
        )));
    }

    /** @return array<string, mixed>|null this task's latest value for the field, so a second call keeps the keys of the first */
    private function ownValue(string $id, string $path): ?array
    {
        $after = AiChangesetItem::query()
            ->whereHas('changeset', fn ($changeset) => $changeset->ofRun($this->run))
            ->whereIn('status', [AiChangesetItemStatus::Pending->value, AiChangesetItemStatus::Accepted->value])
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('target_id', $id)
            ->where('path', $path)
            ->orderByDesc('id')
            ->first()?->after;

        return \is_array($after) ? $after : null;
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
