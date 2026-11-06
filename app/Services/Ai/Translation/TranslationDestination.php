<?php

namespace App\Services\Ai\Translation;

use App\Models\Space\Content;

/**
 * Where a translation of one canonical entry into one locale goes: the
 * existing translation row, or a new one under the language's parent.
 * Resolved in code by {@see TranslationDestinations}, never by the model.
 */
final readonly class TranslationDestination
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public function __construct(
        public Content $source,
        public string $sourceLocale,
        public string $locale,
        public ?Content $translation,
        /** Parent of a new translation row: the parent's row in this language, else the canonical's parent. */
        public ?string $parentId,
        /** `overlay` or `independent`, the canonical's effective i18n mode. */
        public string $mode,
    ) {}

    public function action(): string
    {
        return $this->translation ? self::ACTION_UPDATE : self::ACTION_CREATE;
    }

    public function overlay(): bool
    {
        return $this->mode === 'overlay';
    }

    /**
     * The mapping contract for callers outside the tools (background jobs).
     *
     * @return array{source_id: string, source_version_id: string|null, source_locale: string, locale: string, action: string, destination_id: string|null, destination_version_id: string|null, parent_id: string|null, mode: string}
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->source->id,
            'source_version_id' => $this->source->current_version_id,
            'source_locale' => $this->sourceLocale,
            'locale' => $this->locale,
            'action' => $this->action(),
            'destination_id' => $this->translation?->id,
            'destination_version_id' => $this->translation?->current_version_id,
            'parent_id' => $this->translation ? $this->translation->parent_id : $this->parentId,
            'mode' => $this->mode,
        ];
    }
}
