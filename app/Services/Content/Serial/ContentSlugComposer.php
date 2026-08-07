<?php

namespace App\Services\Content\Serial;

use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Slug\Slugger;
use Illuminate\Support\Str;

/**
 * Builds an entry's slug from its block's slug pattern.
 *
 * Shared by the create action and the create dialog's preview so the value an
 * editor is shown is produced by the same code that will produce the real one —
 * a preview rendered by a second implementation is a preview that lies.
 */
class ContentSlugComposer
{
    public function __construct(
        protected readonly TemplateRenderer $renderer,
        protected readonly ContentSerialAssigner $assigner,
        protected readonly Slugger $slugger,
    ) {}

    /**
     * Render the block's pattern and slugify the result.
     *
     * Falls back to the entry name when the pattern renders empty, which is
     * what happens while the fields it references are still unfilled.
     *
     * @param  array<string, mixed>  $values
     */
    public function compose(
        Block $block,
        ?Content $parent,
        string $languageIso,
        array $values,
        ?string $name,
    ): string {
        $context = $this->assigner->context(
            $block,
            $parent,
            $languageIso,
            $values + ['name' => $name],
        );

        $rendered = trim($this->renderer->render($block->settings->getSlugPattern(), $context));

        return $this->slugger->forContent(
            $rendered !== '' ? $rendered : (string) $name,
            $languageIso,
        );
    }

    /**
     * Suffix the slug until it is free among its siblings, mirroring the
     * uniqueness rule the request validator applies to explicit slugs.
     */
    public function uniqueAmongSiblings(
        string $base,
        ?string $parentId,
        string $languageIso,
        ?string $exceptContentId = null,
    ): string {
        if ($base === '') {
            return $base;
        }

        $candidate = $base;
        $suffix = 1;

        while ($this->slugTaken($candidate, $parentId, $languageIso, $exceptContentId)) {
            $suffix++;
            // A base already at the column limit would push the suffix past it,
            // so the room the suffix needs comes out of the base.
            $candidate = $this->withSuffix($base, (string) $suffix);

            // Past any plausible sibling count a random suffix is a better
            // answer than a 501st probe that might still be taken.
            if ($suffix > 500) {
                return $this->withSuffix($base, strtolower(Str::random(6)));
            }
        }

        return $candidate;
    }

    /**
     * Append a disambiguating suffix without overflowing the slug column.
     */
    protected function withSuffix(string $base, string $suffix): string
    {
        $room = Slugger::CONTENT_SLUG_LENGTH - mb_strlen($suffix) - 1;

        return rtrim(mb_substr($base, 0, max($room, 1)), '-').'-'.$suffix;
    }

    protected function slugTaken(
        string $slug,
        ?string $parentId,
        string $languageIso,
        ?string $exceptContentId,
    ): bool {
        return Content::query()
            ->where('slug', $slug)
            ->where('language_iso', $languageIso)
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->when($exceptContentId !== null, fn ($query) => $query->whereKeyNot($exceptContentId))
            ->whereNull('deleted_at')
            ->exists();
    }
}
