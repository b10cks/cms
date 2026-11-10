<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ContentPositionService
{
    /**
     * Whether the children of `$parent` are ordered by hand. A folder's own
     * `child_sort_by` wins ('manual' → yes, an attribute or content field → no);
     * 'inherit' and the root fall back to the space-level content_sorting toggle.
     * Mirrors allowsManualSort() in ContentTree.vue.
     */
    public function allowsManualSort(Space $space, ?Content $parent): bool
    {
        $settings = $parent?->settings;

        if ($settings?->getChildContentSortField() !== null) {
            return false;
        }

        $column = $settings?->getChildSortColumn();

        return $column === null
            ? $space->settings->isContentSortingEnabled()
            : $column === 'position';
    }

    /**
     * The next free position for a new row. A new entry joins the tree's
     * siblings whatever its language; a new translation joins its language's.
     */
    public function nextPosition(Space $space, ?string $parentId, ?string $translationLanguage): int
    {
        $maxPosition = $this->baseQuery($space, $parentId, $translationLanguage)->max('position');

        return $maxPosition === null ? 0 : ((int) $maxPosition) + 1;
    }

    public function placeNewContent(Space $space, Content $content, ?int $position = null): void
    {
        $this->moveItems($space, collect([$content]), $content->parent_id, null, $position);
    }

    public function moveItems(
        Space $space,
        Collection $items,
        ?string $parentId,
        ?string $afterId = null,
        ?int $position = null,
    ): void {
        $items = $items->filter(fn (Content $content): bool => $content->exists)->values();
        if ($items->isEmpty()) {
            return;
        }

        $treeIds = Content::query()
            ->whereKey($items->pluck('id'))
            ->shownInTree($space->settings->getDefaultLanguage())
            ->pluck('id')
            ->flip();

        $items
            ->groupBy(fn (Content $content): string => $treeIds->has($content->id) ? '' : $content->language_iso)
            ->each(function (Collection $sequenceItems, string $translationLanguage) use ($space, $parentId, $afterId, $position): void {
                $this->resequence($space, $sequenceItems->values(), $parentId, $translationLanguage ?: null, $afterId, $position);
            });
    }

    public function moveItemToPosition(Space $space, Content $content, ?string $parentId, ?int $position): void
    {
        $this->moveItems($space, collect([$content]), $parentId, null, $position);
    }

    private function resequence(
        Space $space,
        Collection $items,
        ?string $parentId,
        ?string $translationLanguage,
        ?string $afterId = null,
        ?int $position = null,
    ): void {
        if ($items->isEmpty()) {
            return;
        }

        $movingIds = $items->pluck('id')->filter()->unique()->values();
        $movingIdSet = $movingIds->flip();
        $siblings = $this->baseQuery($space, $parentId, $translationLanguage)
            ->whereNotIn('id', $movingIds)
            ->orderBy('position')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'position']);

        $insertIndex = $siblings->count();

        if ($afterId !== null && ! $movingIdSet->has($afterId)) {
            $afterIndex = $siblings->search(fn (Content $sibling): bool => $sibling->id === $afterId);
            if ($afterIndex !== false) {
                $insertIndex = $afterIndex + 1;
            }
        } elseif ($position !== null) {
            $insertIndex = max(0, min($position, $siblings->count()));
        }

        $ordered = $siblings
            ->slice(0, $insertIndex)
            ->concat($items)
            ->concat($siblings->slice($insertIndex))
            ->values();

        $changed = $ordered->filter(
            fn (Content $content, int $index): bool => $content->parent_id !== $parentId || (int) $content->position !== $index
        );

        $connection = (new Content)->getConnection();
        $grammar = $connection->getQueryGrammar();

        // One UPDATE per chunk. Ids are escaped and positions are integer literals,
        // so the CASE stays integer-typed on PostgreSQL.
        foreach ($changed->chunk(200) as $chunk) {
            $cases = $chunk
                ->map(fn (Content $content, int $index): string => 'WHEN '.$connection->escape($content->id).' THEN '.$index)
                ->implode(' ');

            Content::query()
                ->whereKey($chunk->pluck('id'))
                ->update([
                    'parent_id' => $parentId,
                    'position' => $connection->raw("CASE {$grammar->wrap('id')} {$cases} ELSE {$grammar->wrap('position')} END"),
                ]);

            foreach ($chunk as $index => $content) {
                $content->parent_id = $parentId;
                $content->position = $index;
            }
        }
    }

    /**
     * One sibling sequence: the rows the tree shows under `$parentId` when
     * `$translationLanguage` is null, else that language's other rows there.
     * An entry made in German sorts among its English siblings, where the
     * tree shows it, not among the German translations.
     */
    private function baseQuery(Space $space, ?string $parentId, ?string $translationLanguage): Builder
    {
        $defaultLanguage = $space->settings->getDefaultLanguage();

        return Content::query()
            ->whereNull('deleted_at')
            ->when(
                $translationLanguage === null,
                fn (Builder $query) => $query->shownInTree($defaultLanguage),
                fn (Builder $query) => $query
                    ->where('language_iso', $translationLanguage)
                    ->whereNot(fn (Builder $query) => $query->shownInTree($defaultLanguage)),
            )
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            );
    }
}
