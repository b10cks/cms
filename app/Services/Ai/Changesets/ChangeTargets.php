<?php

namespace App\Services\Ai\Changesets;

use App\Models\Space\AiChangesetItem;
use App\Services\Ai\Run\ToolError;

/** Resolves the target kind of a changeset item. */
class ChangeTargets
{
    /** Kinds that bulk scopes, schedules and preflight work on. */
    public const TYPES = [AiChangesetItem::TARGET_CONTENT, AiChangesetItem::TARGET_DATA_ENTRY, AiChangesetItem::TARGET_ASSET];

    /** Kinds the AI can read and change. Content is written to its draft, the others wait as proposals. */
    public const PROPOSAL_TYPES = [...self::TYPES, AiChangesetItem::TARGET_BLOCK];

    /** Kinds a changeset can hold for review: proposals plus new asset folders and tags. */
    public const REVIEW_TYPES = [...self::PROPOSAL_TYPES, AiChangesetItem::TARGET_ASSET_FOLDER, AiChangesetItem::TARGET_ASSET_TAG];

    public function for(string $type): ChangeTarget
    {
        return match ($type) {
            AiChangesetItem::TARGET_CONTENT => app(ContentTarget::class),
            AiChangesetItem::TARGET_DATA_ENTRY => app(DataEntryTarget::class),
            AiChangesetItem::TARGET_ASSET => app(AssetTarget::class),
            AiChangesetItem::TARGET_BLOCK => app(BlockTarget::class),
            // New folders and tags of asset operations; only created, never edited.
            AiChangesetItem::TARGET_ASSET_FOLDER => app(AssetFolderTarget::class),
            AiChangesetItem::TARGET_ASSET_TAG => app(AssetTagTarget::class),
            default => throw new ToolError('target_type must be one of: '.implode(', ', self::PROPOSAL_TYPES).'.'),
        };
    }
}
