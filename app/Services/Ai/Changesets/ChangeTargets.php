<?php

namespace App\Services\Ai\Changesets;

use App\Models\Management\AiChangesetItem;
use App\Services\Ai\Run\ToolError;

/** Resolves the target kind of a changeset item. */
class ChangeTargets
{
    public const TYPES = [AiChangesetItem::TARGET_CONTENT, AiChangesetItem::TARGET_DATA_ENTRY, AiChangesetItem::TARGET_ASSET];

    public function for(string $type): ChangeTarget
    {
        return match ($type) {
            AiChangesetItem::TARGET_CONTENT => app(ContentTarget::class),
            AiChangesetItem::TARGET_DATA_ENTRY => app(DataEntryTarget::class),
            AiChangesetItem::TARGET_ASSET => app(AssetTarget::class),
            default => throw new ToolError('target_type must be one of: '.implode(', ', self::TYPES).'.'),
        };
    }
}
