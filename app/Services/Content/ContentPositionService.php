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

    public function nextPosition(?string $parentId, string $languageIso): int
    {
        $maxPosition = $this->baseQuery($parentId, $languageIso)->max('position');

        return $maxPosition === null ? 0 : ((int) $maxPosition) + 1;
    }

    public function placeNewContent(Content $content, ?int $position = null): void
    {
        $this->resequence(
            collect([$content]),
            $content->parent_id,
            $content->language_iso,
            null,
            $position,
        );
    }

    public function moveItems(
        Collection $items,
        ?string $parentId,
        ?string $afterId = null,
        ?int $position = null,
    ): void {
        $items
            ->filter(fn (Content $content): bool => $content->exists)
            ->groupBy('language_iso')
            ->each(function (Collection $languageItems, string $languageIso) use ($parentId, $afterId, $position): void {
                $this->resequence($languageItems->values(), $parentId, $languageIso, $afterId, $position);
            });
    }

    public function moveItemToPosition(Content $content, ?string $parentId, ?int $position): void
    {
        $this->resequence(
            collect([$content]),
            $parentId,
            $content->language_iso,
            null,
            $position,
        );
    }

    private function resequence(
        Collection $items,
        ?string $parentId,
        string $languageIso,
        ?string $afterId = null,
        ?int $position = null,
    ): void {
        if ($items->isEmpty()) {
            return;
        }

        $movingIds = $items->pluck('id')->filter()->unique()->values();
        $movingIdSet = $movingIds->flip();
        $siblings = $this->baseQuery($parentId, $languageIso)
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
        $table = $grammar->wrapTable((new Content)->getTable());
        $id = $grammar->wrap('id');
        $parent = $grammar->wrap('parent_id');
        $positionColumn = $grammar->wrap('position');
        $updatedAt = $grammar->wrap('updated_at');
        $deletedAt = $grammar->wrap('deleted_at');

        // Bound the parameter count for SQLite as well as server databases.
        foreach ($changed->chunk(200) as $chunk) {
            $cases = [];
            $bindings = [$parentId];

            foreach ($chunk as $index => $content) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $content->id;
                $bindings[] = $index;
            }

            $bindings[] = now();
            array_push($bindings, ...$chunk->pluck('id')->all());
            $placeholders = implode(', ', array_fill(0, $chunk->count(), '?'));
            $caseSql = implode(' ', $cases);

            $connection->update(
                "UPDATE {$table} SET {$parent} = ?, {$positionColumn} = CASE {$id} {$caseSql} END, {$updatedAt} = ? WHERE {$id} IN ({$placeholders}) AND {$deletedAt} IS NULL",
                $bindings,
            );

            foreach ($chunk as $index => $content) {
                $content->parent_id = $parentId;
                $content->position = $index;
            }
        }
    }

    private function baseQuery(?string $parentId, string $languageIso): Builder
    {
        return Content::query()
            ->whereNull('deleted_at')
            ->where('language_iso', $languageIso)
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            );
    }
}
