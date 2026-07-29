<?php

namespace App\Services\Content;

use App\Actions\Content\CreateContent;
use App\Actions\Content\UpdateContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Content\Serial\SerialFieldConfig;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentTreeOperationService
{
    /** @var array<string, Block|null> */
    protected array $blockCache = [];

    public function __construct(
        protected ContentI18nService $contentI18nService,
        protected ContentHierarchyValidator $contentHierarchyValidator,
        protected ContentPositionService $contentPositionService,
        protected CreateContent $createContent,
        protected UpdateContent $updateContent,
        protected SearchService $searchService,
    ) {}

    public function createItem(
        ?Content $parent,
        array $attributes,
        Space $space,
        Authenticatable|User|null $owner,
    ): array {
        return (new Content)->getConnection()->transaction(function () use ($parent, $attributes, $space, $owner): array {
            $defaultLanguage = $space->settings->getDefaultLanguage();
            $parentFamily = $parent ? $this->contentI18nService->getFamily($parent)->keyBy('language_iso') : collect();
            $canonicalParent = $parent ? $parentFamily->get($defaultLanguage) ?? $parent : null;

            $canonical = new Content;
            $this->createContent->execute(
                [
                    'block_id' => $attributes['block_id'],
                    'parent_id' => $canonicalParent?->id,
                    'name' => $attributes['name'],
                    // No slug means the block builds one from its slug pattern,
                    // which CreateContent applies after allocating serials.
                    'slug' => filled($attributes['slug'] ?? null)
                        ? $this->makeUniqueSlug($attributes['slug'], $canonicalParent?->id, $defaultLanguage)
                        : null,
                    'language_iso' => $defaultLanguage,
                    'content' => $attributes['content'] ?? [],
                    'settings' => $attributes['settings'] ?? [],
                    'position' => $attributes['position'] ?? null,
                ],
                $canonical,
                $space,
                $owner,
            );

            return [
                'created' => [$canonical->fresh()],
                'warnings' => [],
            ];
        });
    }

    public function moveItems(
        array $orderedIds,
        ?string $parentId,
        ?string $afterId,
        Space $space,
        ?int $position = null,
    ): array {
        $connection = (new Content)->getConnection();

        return $connection->transaction(function () use ($connection, $orderedIds, $parentId, $afterId, $space, $position): array {
            $warnings = [];
            $sortingEnabled = $space->settings->isContentSortingEnabled();
            // Callers pass an already root-resolved selection (the controller
            // resolves it once for authorization) — no need to resolve again.
            $normalizedIds = $orderedIds;
            $items = Content::query()
                ->with(['block'])
                ->whereIn('id', $normalizedIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $orderedItems = collect($normalizedIds)
                ->map(fn (string $id) => $items->get($id))
                ->filter()
                ->values();

            if ($orderedItems->isEmpty()) {
                return ['warnings' => []];
            }

            $targetParent = $parentId
                ? Content::query()->with('block')->whereNull('deleted_at')->findOrFail($parentId)
                : null;
            $after = $afterId ? Content::query()->whereNull('deleted_at')->findOrFail($afterId) : null;

            $this->ensureAfterTargetIsCompatible($orderedItems, $targetParent?->id, $after);
            $this->validateBatchPlacement($orderedItems, $targetParent, $space);
            $this->reassignParent($orderedItems, $targetParent?->id);
            if ($sortingEnabled) {
                $this->contentPositionService->moveItems($orderedItems, $targetParent?->id, $after?->id, $position);
            }

            $familyCache = $this->buildFamilyCache($orderedItems);
            $defaultLanguage = $space->settings->getDefaultLanguage();
            $enabledLanguages = collect($space->settings->getEnabledLanguages())
                ->prepend($defaultLanguage)
                ->unique()
                ->values();
            $parentFamily = $targetParent
                ? $this->contentI18nService->getFamily($targetParent)->keyBy('language_iso')
                : collect();
            $afterFamily = $after ? $this->contentI18nService->getFamily($after)->keyBy('language_iso') : collect();
            $languages = $enabledLanguages
                ->reject(fn (string $languageIso) => $languageIso === $defaultLanguage)
                ->values();

            foreach ($languages as $languageIso) {
                $translatedItems = $orderedItems
                    ->map(function (Content $item) use ($familyCache, $languageIso): ?Content {
                        $canonicalId = $this->contentI18nService->getCanonicalId($item);

                        return $familyCache[$canonicalId]->firstWhere('language_iso', $languageIso);
                    })
                    ->filter()
                    ->values();

                if ($translatedItems->isEmpty()) {
                    continue;
                }

                $translatedParent = $targetParent ? $parentFamily->get($languageIso) : null;
                if ($targetParent && ! $translatedParent) {
                    $warnings[] = $this->makeWarning(
                        'missing-parent-translation',
                        $languageIso,
                        null,
                        'Skipped a translated move because the destination parent does not exist in that language.',
                    );

                    continue;
                }

                $translatedAfter = $after ? $afterFamily->get($languageIso) : null;
                if ($after && ! $translatedAfter) {
                    $warnings[] = $this->makeWarning(
                        'missing-after-translation',
                        $languageIso,
                        null,
                        'Skipped a translated move because the sibling anchor does not exist in that language.',
                    );

                    continue;
                }

                $this->ensureAfterTargetIsCompatible($translatedItems, $translatedParent?->id, $translatedAfter);
                $this->validateBatchPlacement($translatedItems, $translatedParent, $space);
                $this->reassignParent($translatedItems, $translatedParent?->id);
                if ($sortingEnabled) {
                    $this->contentPositionService->moveItems(
                        $translatedItems,
                        $translatedParent?->id,
                        $translatedAfter?->id,
                        $position,
                    );
                }
            }

            $space->touch('content_updated_at');
            $connection->afterCommit(static fn (): mixed => app(ContentMenuCache::class)->invalidate($space->id));

            return [
                'warnings' => $warnings,
            ];
        });
    }

    public function deleteSubtrees(array $ids, Space $space): array
    {
        return (new Content)->getConnection()->transaction(function () use ($ids, $space): array {
            $canonicalIds = $this->collectCanonicalSubtreeIds($ids);
            $familyRows = Content::query()
                ->where(function ($query) use ($canonicalIds) {
                    $query->whereIn('id', $canonicalIds)->orWhereIn('i18n_parent_id', $canonicalIds);
                })
                ->whereNull('deleted_at')
                ->get();

            foreach ($familyRows as $row) {
                $this->searchService->removeContent($row, $space);
                $row->delete();
            }

            $space->touch('content_updated_at');

            return [
                'warnings' => [],
            ];
        });
    }

    public function duplicateSubtrees(
        array $orderedIds,
        ?string $parentId,
        ?string $afterId,
        Space $space,
        Authenticatable|User|null $owner,
        ?int $position = null,
    ): array {
        $connection = (new Content)->getConnection();

        return $connection->transaction(function () use ($connection, $orderedIds, $parentId, $afterId, $space, $owner, $position): array {
            // Callers pass an already root-resolved selection (the controller
            // resolves it once for authorization) — no need to resolve again.
            $normalizedIds = $orderedIds;
            $sources = Content::query()
                ->with(['block', 'current_version', 'children'])
                ->whereIn('id', $normalizedIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');
            $targetParent = $parentId
                ? Content::query()->with('block')->whereNull('deleted_at')->findOrFail($parentId)
                : null;
            $after = $afterId ? Content::query()->whereNull('deleted_at')->findOrFail($afterId) : null;

            $this->ensureAfterTargetIsCompatible(collect(), $targetParent?->id, $after, $normalizedIds);

            $createdRoots = collect();

            foreach ($normalizedIds as $sourceId) {
                $source = $sources->get($sourceId);
                if (! $source) {
                    continue;
                }

                if ($source->i18n_parent_id !== null) {
                    throw new \InvalidArgumentException('Only canonical content items can currently be duplicated.');
                }

                $this->contentHierarchyValidator->validatePlacement(
                    $space,
                    $source->block,
                    $targetParent,
                    null,
                    $source->language_iso,
                );

                $createdRoots->push($this->duplicateNodeRecursive($source, $targetParent?->id, $space, $owner));
            }

            if ($createdRoots->isNotEmpty()) {
                if ($space->settings->isContentSortingEnabled()) {
                    $this->contentPositionService->moveItems($createdRoots, $targetParent?->id, $after?->id, $position);
                }
                $space->touch('content_updated_at');
                $connection->afterCommit(static fn (): mixed => app(ContentMenuCache::class)->invalidate($space->id));
            }

            return [
                'created' => $createdRoots->map(fn (Content $content) => $content->fresh())->all(),
                'warnings' => [],
            ];
        });
    }

    public function updateBlock(
        Content $content,
        string $blockId,
        Space $space,
        Authenticatable|User|null $owner,
    ): array {
        return $content->getConnection()->transaction(function () use ($content, $blockId, $space, $owner): array {
            $family = $this->contentI18nService->getFamily($content);

            foreach ($family as $familyItem) {
                $this->updateContent->execute(
                    [
                        'block_id' => $blockId,
                    ],
                    $familyItem,
                    $space,
                    $owner,
                );
            }

            return ['warnings' => []];
        });
    }

    public function resolveOrderedRootSelection(array $ids): array
    {
        $items = Content::query()->whereIn('id', $ids)->whereNull('deleted_at')->get()->keyBy('id');

        $selectedIds = collect($ids)->filter(fn (string $id) => $items->has($id))->values();

        // Fetch the ancestor chains level by level instead of one find() per
        // ancestor. A missing (deleted) ancestor terminates its chain, exactly
        // like the previous per-row walk did.
        $parentById = $items->map(fn (Content $item): ?string => $item->parent_id);
        $pending = $parentById->values()->filter()->unique()
            ->reject(fn (string $id): bool => $parentById->has($id))
            ->values();

        while ($pending->isNotEmpty()) {
            $ancestors = Content::query()
                ->whereNull('deleted_at')
                ->whereIn('id', $pending)
                ->pluck('parent_id', 'id');

            foreach ($pending as $id) {
                $parentById->put($id, $ancestors->get($id));
            }

            $pending = $ancestors->values()->filter()->unique()
                ->reject(fn (string $id): bool => $parentById->has($id))
                ->values();
        }

        return $selectedIds
            ->filter(function (string $id) use ($selectedIds, $parentById): bool {
                $parentId = $parentById->get($id);

                while ($parentId) {
                    if ($selectedIds->contains($parentId)) {
                        return false;
                    }

                    $parentId = $parentById->get($parentId);
                }

                return true;
            })
            ->values()
            ->all();
    }

    protected function duplicateNodeRecursive(
        Content $source,
        ?string $parentId,
        Space $space,
        Authenticatable|User|null $owner,
    ): Content {
        $source->loadMissing([
            'current_version',
            'children',
        ]);

        $copy = new Content;
        $baseSlug = $this->makeCopySlugBase($source->slug);
        $this->createContent->execute(
            [
                'block_id' => $source->block_id,
                'parent_id' => $parentId,
                'name' => $source->name,
                'slug' => $this->makeUniqueSlug($baseSlug, $parentId, $source->language_iso),
                'language_iso' => $source->language_iso,
                'content' => $this->withoutSerialValues($source->block_id, $source->current_version?->content ?? []),
                'settings' => $source->settings?->toArray() ?? [],
            ],
            $copy,
            $space,
            $owner,
        );

        // Batch the next level's relations so the recursion does not lazy
        // load them one node at a time.
        $source->children->loadMissing(['current_version', 'children']);

        foreach ($source->children as $child) {
            $this->duplicateNodeRecursive($child, $copy->id, $space, $owner);
        }

        // No fresh() here: duplicateSubtrees refreshes the returned roots
        // before building its response, and child return values are unused.
        return $copy;
    }

    protected function makeCopySlugBase(string $slug): string
    {
        return Str::finish($slug, '-copy') === $slug ? "{$slug}-2" : "{$slug}-copy";
    }

    /**
     * Strip serial values from a payload being duplicated.
     *
     * A serial is an identifier of the *source* entry. Carrying it over would
     * either collide (editable fields claim the submitted value) or be
     * discarded anyway (readonly fields), so the copy always draws fresh ones.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    protected function withoutSerialValues(string $blockId, array $content): array
    {
        // Loaded explicitly: relations re-loaded by content lifecycle events
        // carry a restricted column set without the schema. Memoized per run —
        // a duplicated subtree repeats the same handful of blocks.
        $block = $this->blockCache[$blockId] ??= Block::query()->find($blockId);

        if (! $block) {
            return $content;
        }

        foreach (array_keys(SerialFieldConfig::collect($block->schema)) as $key) {
            unset($content[$key]);
        }

        return $content;
    }

    protected function makeUniqueSlug(
        string $baseSlug,
        ?string $parentId,
        string $languageIso,
        ?string $ignoreId = null,
    ): string {
        $slug = Str::slug($baseSlug);
        $suffix = 2;

        // Every candidate ("slug", "slug-2", "slug-3", …) shares the slugged
        // base as prefix, so one prefix query replaces an exists() per attempt.
        $existing = Content::query()
            ->where('parent_id', $parentId)
            ->where('language_iso', $languageIso)
            ->where('slug', 'LIKE', "{$slug}%")
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->pluck('slug')
            ->flip();

        while ($existing->has($slug)) {
            $slug = Str::slug("{$baseSlug}-{$suffix}");
            $suffix++;
        }

        return $slug;
    }

    protected function reassignParent(Collection $items, ?string $parentId): void
    {
        foreach ($items->unique('id') as $item) {
            if ($item->parent_id === $parentId) {
                continue;
            }

            $item->forceFill([
                'parent_id' => $parentId,
            ])->save();
        }
    }

    protected function validateBatchPlacement(Collection $items, ?Content $targetParent, Space $space): void
    {
        EloquentCollection::make($items->values()->all())->loadMissing('block');

        // The target parent's ancestor chain is the same for every item, so
        // walk it once instead of once per item.
        $parentChainIds = $targetParent ? $this->collectAncestorChainIds($targetParent) : [];

        foreach ($items as $item) {
            if ($targetParent && $targetParent->id === $item->id) {
                throw new \InvalidArgumentException('A content item cannot become its own parent.');
            }

            if ($targetParent && isset($parentChainIds[$item->id])) {
                throw new \InvalidArgumentException('Cannot move content: would create circular reference.');
            }

            $this->contentHierarchyValidator->validatePlacement(
                $space,
                $item->block,
                $targetParent,
                $item,
                $item->language_iso,
            );
        }
    }

    protected function ensureAfterTargetIsCompatible(
        Collection $items,
        ?string $parentId,
        ?Content $after,
        array $movingIds = [],
    ): void {
        if (! $after) {
            return;
        }

        $allMovingIds = collect($movingIds)->concat($items->pluck('id'))->filter()->unique()->all();

        if (\in_array($after->id, $allMovingIds, true)) {
            throw new \InvalidArgumentException('Cannot place items after themselves.');
        }

        if ($after->parent_id !== $parentId) {
            throw new \InvalidArgumentException('The requested sibling anchor is not in the destination container.');
        }
    }

    protected function buildFamilyCache(Collection $items): array
    {
        $cache = [];

        foreach ($items as $item) {
            $canonicalId = $this->contentI18nService->getCanonicalId($item);
            if (! isset($cache[$canonicalId])) {
                $cache[$canonicalId] = $this->contentI18nService->getFamily($item)->keyBy('language_iso');
            }
        }

        return $cache;
    }

    protected function makeWarning(
        string $type,
        ?string $languageIso = null,
        ?string $contentId = null,
        ?string $message = null,
    ): array {
        return [
            'type' => $type,
            'language_iso' => $languageIso,
            'content_id' => $contentId,
            'message' => $message,
        ];
    }

    /**
     * The content's own id plus every ancestor id above it, as a lookup set.
     *
     * @return array<string, true>
     */
    protected function collectAncestorChainIds(Content $content): array
    {
        $ids = [$content->id => true];
        $current = $content;

        while ($current !== null && $current->parent_id !== null && ! isset($ids[$current->parent_id])) {
            $current = $current->relationLoaded('parent')
                ? $current->getRelation('parent')
                : Content::query()->whereNull('deleted_at')->select(['id', 'parent_id'])->find($current->parent_id);

            if ($current !== null) {
                $ids[$current->id] = true;
            }
        }

        return $ids;
    }

    protected function collectCanonicalSubtreeIds(array $ids): array
    {
        $queue = collect($ids)->unique()->values();
        $seen = [];

        while ($queue->isNotEmpty()) {
            $chunk = $queue->splice(0, 100)->all();
            $rows = Content::query()->whereIn('id', $chunk)->whereNull('deleted_at')->get(['id', 'i18n_parent_id']);

            $canonicalIds = [];
            foreach ($rows as $row) {
                $canonicalId = $this->contentI18nService->getCanonicalId($row);
                if (isset($seen[$canonicalId])) {
                    continue;
                }

                $seen[$canonicalId] = true;
                $canonicalIds[] = $canonicalId;
            }

            if ($canonicalIds === []) {
                continue;
            }

            // One family + one children query per BFS level instead of one
            // pair per canonical node.
            Content::query()
                ->where(function ($query) use ($canonicalIds) {
                    $query->whereIn('id', $canonicalIds)->orWhereIn('i18n_parent_id', $canonicalIds);
                })
                ->whereNull('deleted_at')
                ->chunk(100, function (EloquentCollection $familyRows) use (&$queue): void {
                    $children = Content::query()
                        ->whereIn('parent_id', $familyRows->pluck('id'))
                        ->whereNull('deleted_at')
                        ->pluck('id');

                    $queue = $queue->concat($children)->unique()->values();
                });
        }

        return array_keys($seen);
    }
}
