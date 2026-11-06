<?php

namespace App\Services\Ai\Changesets;

use App\Models\Management\Space;
use App\Models\Space\AssetFolder;
use App\Models\User;
use App\Services\Ai\Run\ToolError;

/**
 * New asset folders. A proposal target only: folders are proposed with
 * `propose_asset_structure` and created when accepted, never edited here.
 */
class AssetFolderTarget implements ChangeTarget
{
    public function viewAbility(): string
    {
        return 'asset_folders.view';
    }

    public function editAbility(): string
    {
        return 'asset_folders.manage';
    }

    public function find(string $id, ?string $versionId = null): ?TargetSnapshot
    {
        $folder = AssetFolder::query()->find($id);

        return $folder ? new TargetSnapshot($folder, (string) $folder->name, ['name' => $folder->name, 'parent_id' => $folder->parent_id]) : null;
    }

    public function validate(Space $space, TargetSnapshot $snapshot, array $patches): array
    {
        throw new ToolError('Asset folders are not edited by AI. Propose new folders with propose_asset_structure.');
    }

    public function apply(Space $space, TargetSnapshot $current, array $document, User $actor, string $message): void
    {
        throw new ToolError('Asset folders are not edited by AI.');
    }
}
