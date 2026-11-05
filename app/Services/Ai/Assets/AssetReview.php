<?php

namespace App\Services\Ai\Assets;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangesetItem;

/**
 * The `review` data of asset proposals and the rules that read it.
 *
 * - `group`: label of the review group, e.g. "Product shots on white"
 * - `reason`: why the model proposes this, or why a new folder or tag is justified
 * - `examples`: asset IDs a new folder or tag is justified by
 * - `flags`: `[{code, message}]` set when proposed: `uncertain` (the model asks
 *   for a person's look), `missing_required` (the destination folder requires
 *   fields that stay empty)
 * - `problem`: `{code, message}` from the last accept that could not apply it
 * - `depends_on`: IDs of folder and tag proposals this item needs
 * - `bundle`: shared by the items of one asset proposal that moves the asset;
 *   they are accepted together or not at all
 * - `labels`: display names of the folder and tag IDs and refs in the item
 * - `evidence`: what the change rests on. `basis` is `image` (with `run_id`,
 *   `message` and `sha256` of the delivered image), `metadata` (with the
 *   `sources` it derives from) or `instruction` (with the user's `quote`)
 */
final class AssetReview
{
    public const TYPES = [AiChangesetItem::TARGET_ASSET, AiChangesetItem::TARGET_ASSET_FOLDER, AiChangesetItem::TARGET_ASSET_TAG];

    public const STRUCTURE_TYPES = [AiChangesetItem::TARGET_ASSET_FOLDER, AiChangesetItem::TARGET_ASSET_TAG];

    public const BASIS_IMAGE = 'image';

    public const BASIS_METADATA = 'metadata';

    public const BASIS_INSTRUCTION = 'instruction';

    public const FLAG_UNCERTAIN = 'uncertain';

    public const FLAG_MISSING_REQUIRED = 'missing_required';

    public const PROBLEM_DEPENDENCY_PENDING = 'dependency_pending';

    public const PROBLEM_DEPENDENCY_REJECTED = 'dependency_rejected';

    public const PROBLEM_DEPENDENCY_MISSING = 'dependency_missing';

    public const PROBLEM_BUNDLE_INCOMPLETE = 'bundle_incomplete';

    public const PROBLEM_INVALID = 'invalid';

    public const PROBLEM_CHANGED = 'changed';

    /** Prefix of a reference to a proposed, not yet created folder or tag: `new:<item id>`. */
    public const NEW_PREFIX = 'new:';

    /** Whether the item belongs to the asset domain and is applied by {@see AssetChangesetApplier}. */
    public static function handles(string $targetType): bool
    {
        return \in_array($targetType, self::TYPES, true);
    }

    /**
     * Whether "accept all" must leave the item to an individual decision: new
     * folders and tags are approved on their own first, and conflicts, failed
     * accepts and flagged items need a person's look.
     */
    public static function excludedFromBulk(AiChangesetItem $item): bool
    {
        return \in_array($item->target_type, self::STRUCTURE_TYPES, true)
            || $item->status === AiChangesetItemStatus::Conflict
            || ($item->review['flags'] ?? []) !== []
            || isset($item->review['problem']);
    }

    public static function newRef(string $itemId): string
    {
        return self::NEW_PREFIX.$itemId;
    }

    /** The proposal ID of a `new:` reference, or null for a plain ID. */
    public static function refItemId(mixed $ref): ?string
    {
        return \is_string($ref) && str_starts_with($ref, self::NEW_PREFIX) ? substr($ref, \strlen(self::NEW_PREFIX)) : null;
    }

    /**
     * Folder and tag references an asset item points at.
     *
     * @return list<string>
     */
    public static function refs(AiChangesetItem $item): array
    {
        return match ($item->path) {
            'tags' => array_values(\is_array($item->after['add'] ?? null) ? $item->after['add'] : []),
            'folder_id' => \is_string($item->after) ? [$item->after] : [],
            default => [],
        };
    }

    /** Comparison key for folder and tag names: one Unicode form, one case. */
    public static function foldName(string $name): string
    {
        $normalized = class_exists(\Normalizer::class) ? (\Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name) : $name;

        return mb_strtolower(trim($normalized));
    }
}
