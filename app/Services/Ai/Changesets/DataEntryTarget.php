<?php

namespace App\Services\Ai\Changesets;

use App\Actions\DataEntry\UpdateDataEntry;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Ai\Run\ToolError;
use App\Services\Space\ShapeValue;
use Illuminate\Support\Facades\Validator;

/**
 * Data entries. The document is `{value, dimensions: {<key>: value}}` with
 * shaped values decoded; paths are `value` or `dimensions.<key>`.
 */
class DataEntryTarget implements ChangeTarget
{
    public function __construct(private readonly UpdateDataEntry $update) {}

    public function viewAbility(): string
    {
        return 'data_entries.view';
    }

    public function editAbility(): string
    {
        return 'data_entries.manage';
    }

    public function find(string $id, ?string $versionId = null): ?TargetSnapshot
    {
        $entry = DataEntry::query()->with('dataSource')->find($id);
        $shape = $entry?->dataSource?->shape;

        if (! $entry) {
            return null;
        }

        return new TargetSnapshot($entry, (string) $entry->key, [
            'value' => ShapeValue::decode($entry->value, $shape),
            'dimensions' => array_map(fn ($value) => ShapeValue::decode($value, $shape), $entry->dimensions ?? []),
        ]);
    }

    public function validate(Space $space, TargetSnapshot $snapshot, array $patches): array
    {
        /** @var DataEntry $entry */
        $entry = $snapshot->model;
        $source = $entry->dataSource ?? throw new ToolError('This entry has no data source.');
        $dimensions = array_column($source->dimensions ?? [], 'key');
        $problems = [];

        foreach ($patches as $path => $value) {
            $path = (string) $path;
            $dimension = str_starts_with($path, 'dimensions.') ? substr($path, 11) : null;

            if ($path !== 'value' && ! \in_array($dimension, $dimensions, true)) {
                $problems[] = "{$path}: use `value` or `dimensions.<key>` with a dimension of the data source (".implode(', ', $dimensions ?: ['none']).').';

                continue;
            }

            $rules = $source->hasShape()
                ? ShapeValue::rulesFor($value, $source->shape, 'value', enforceRequired: $value !== null && $dimension === null)
                : ['value' => ['nullable', 'string']];
            $validator = Validator::make(['value' => $value], $rules);

            foreach ($validator->errors()->all() as $message) {
                $problems[] = "{$path}: {$message}";
            }
        }

        if ($problems !== []) {
            throw new ToolError("The patch is invalid:\n- ".implode("\n- ", $problems));
        }

        return array_map(fn () => $source->hasShape() ? 'shape' : 'text', $patches);
    }

    public function apply(Space $space, TargetSnapshot $current, array $document, User $actor, string $message): void
    {
        /** @var DataEntry $entry */
        $entry = $current->model;
        /** @var DataSource $source */
        $source = $entry->dataSource;

        $this->update->execute($entry, $source, [
            'value' => $document['value'] ?? null,
            'dimensions' => ($document['dimensions'] ?? []) ?: null,
        ]);
    }
}
