<?php

namespace App\Enums;

use App\Models\Space\AiChangesetItem;
use App\Models\Space\Asset;
use Illuminate\Database\Eloquent\Model;

/**
 * What an operation does, which decides the entries it enumerates and the
 * plan it needs. Editorial policy lives in the skill; this only covers what
 * code decides deterministically.
 */
enum AiOperationKind: string
{
    /** Canonical content entries; missing locale entries, empty fields and stale translations. */
    case Translation = 'translation';

    /** Assets of a folder tree; image inspection, metadata, tags and folder organisation. */
    case Assets = 'assets';

    /** Any bulk skill over content, data entries or assets; schedules use it. */
    case General = 'general';

    /** Image types vision models accept. Anything else is reported as unsupported. */
    public const VISION_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** The target type this kind works on, or null when the scope decides (general). */
    public function targetType(): ?string
    {
        return match ($this) {
            self::Translation => AiChangesetItem::TARGET_CONTENT,
            self::Assets => AiChangesetItem::TARGET_ASSET,
            self::General => null,
        };
    }

    /** Translation works from source entries only; locale entries are its destinations. */
    public function canonicalOnly(): bool
    {
        return $this === self::Translation;
    }

    /** Why an entry cannot be worked on, decided before any model call; null when it can. */
    public function unsupportedReason(Model $entry): ?string
    {
        if ($this === self::Assets && $entry instanceof Asset && ! \in_array(strtolower((string) $entry->mime_type), self::VISION_MIME_TYPES, true)) {
            return 'unsupported_file_type';
        }

        return null;
    }
}
