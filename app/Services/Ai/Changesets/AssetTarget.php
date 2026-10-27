<?php

namespace App\Services\Ai\Changesets;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\User;
use App\Services\Ai\Run\ToolError;
use App\Services\Asset\AssetMetadataFieldResolver;

/**
 * Assets. The document is `{fields: {<locale>: {<field>: text}}}`, the
 * metadata fields of the space's asset settings; paths are
 * `fields.<locale>.<field>`.
 */
class AssetTarget implements ChangeTarget
{
    private const MAX_LENGTH = 2000;

    public function __construct(private readonly AssetMetadataFieldResolver $fields) {}

    public function viewAbility(): string
    {
        return 'assets.view';
    }

    public function editAbility(): string
    {
        return 'assets.manage';
    }

    public function find(string $id, ?string $versionId = null): ?TargetSnapshot
    {
        $asset = Asset::query()->with('folder')->find($id);

        if (! $asset) {
            return null;
        }

        $data = \is_array($asset->data) ? $asset->data : [];

        return new TargetSnapshot($asset, (string) $asset->filename, ['fields' => $data['fields'] ?? []]);
    }

    public function validate(Space $space, TargetSnapshot $snapshot, array $patches): array
    {
        /** @var Asset $asset */
        $asset = $snapshot->model;
        $keys = array_column($this->fields->getEffectiveClassificationFieldsForAsset($space, $asset), 'key');
        $locales = $space->settings->getEnabledLanguages();
        $problems = [];

        foreach ($patches as $path => $value) {
            $segments = explode('.', (string) $path);

            if (\count($segments) !== 3 || $segments[0] !== 'fields' || ! \in_array($segments[1], $locales, true) || ! \in_array($segments[2], $keys, true)) {
                $problems[] = "{$path}: use fields.<locale>.<field> with a locale of the space (".implode(', ', $locales).') and an asset field ('.implode(', ', $keys ?: ['none']).').';
            } elseif ($value !== null && (! \is_string($value) || mb_strlen($value) > self::MAX_LENGTH)) {
                $problems[] = "{$path}: must be text of at most ".self::MAX_LENGTH.' characters.';
            }
        }

        if ($problems !== []) {
            throw new ToolError("The patch is invalid:\n- ".implode("\n- ", $problems));
        }

        return array_map(fn () => 'textarea', $patches);
    }

    public function apply(Space $space, TargetSnapshot $current, array $document, User $actor, string $message): void
    {
        /** @var Asset $asset */
        $asset = $current->model;
        $asset->data = [...(\is_array($asset->data) ? $asset->data : []), 'fields' => $document['fields'] ?? []];
        $asset->save();
    }
}
