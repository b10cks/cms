<?php

namespace App\Services\Ai\Changesets;

use App\Models\Management\Space;
use App\Models\Space\AssetTag;
use App\Models\User;
use App\Services\Ai\Run\ToolError;

/**
 * New asset tags. A proposal target only: tags are proposed with
 * `propose_asset_structure` and created when accepted, never edited here.
 */
class AssetTagTarget implements ChangeTarget
{
    public function viewAbility(): string
    {
        return 'asset_tags.view';
    }

    public function editAbility(): string
    {
        return 'asset_tags.manage';
    }

    public function find(string $id, ?string $versionId = null): ?TargetSnapshot
    {
        $tag = AssetTag::query()->find($id);

        return $tag ? new TargetSnapshot($tag, (string) $tag->name, ['name' => $tag->name]) : null;
    }

    public function validate(Space $space, TargetSnapshot $snapshot, array $patches): array
    {
        throw new ToolError('Asset tags are not edited by AI. Propose new tags with propose_asset_structure.');
    }

    public function apply(Space $space, TargetSnapshot $current, array $document, User $actor, string $message): void
    {
        throw new ToolError('Asset tags are not edited by AI.');
    }
}
