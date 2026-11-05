<?php

namespace App\Services\Ai\Changesets;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\User;
use App\Services\Ai\Assets\AssetChangesetApplier;
use App\Services\Ai\Assets\AssetFieldRules;
use App\Services\Ai\Run\ToolError;

/**
 * Assets. The document is `{fields: {<language>: {<field>: text}}}`, the
 * metadata fields of the space's asset settings; paths are
 * `fields.<language>.<field>` with the keys asset data is stored under
 * (`_default` for the default language). Tag and folder changes are proposed
 * with `propose_asset_changes` and applied by {@see AssetChangesetApplier}.
 */
class AssetTarget implements ChangeTarget
{
    public function __construct(private readonly AssetFieldRules $rules) {}

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
        $fields = [];
        $problems = [];

        foreach ($patches as $path => $value) {
            $segments = explode('.', (string) $path);

            if (\count($segments) !== 3 || $segments[0] !== 'fields') {
                $problems[] = "{$path}: use fields.<language>.<field>.";

                continue;
            }

            $fields[$segments[1]][$segments[2]] = $value;
        }

        $problems = [...$problems, ...$this->rules->problems($space, $asset->relationLoaded('folder') ? $asset->folder : $asset->folder_id, $fields)];

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
