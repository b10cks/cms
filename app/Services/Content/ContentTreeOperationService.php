<?php

namespace App\Services\Content;

use App\Actions\Content\CreateContent;
use App\Actions\Content\UpdateContent;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentTreeOperationService
{
    public function __construct(
        protected ContentI18nService $contentI18nService,
        protected ContentHierarchyValidator $contentHierarchyValidator,
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
        return DB::transaction(function () use ($parent, $attributes, $space, $owner): array {
            $defaultLanguage = $space->settings->getDefaultLanguage();
            $parentFamily = $parent ? $this->contentI18nService->getFamily($parent)->keyBy('language_iso') : collect();
            $canonicalParent = $parent ? $parentFamily->get($defaultLanguage) ?? $parent : null;

            $canonical = new Content();
            $this->createContent->execute(
                [
                    'block_id' => $attributes['block_id'],
                    'parent_id' => $canonicalParent?->id,
                    'name' => $attributes['name'],
                    'slug' => $this->makeUniqueSlug($attributes['slug'], $canonicalParent?->id, $defaultLanguage),
                    'language_iso' => $defaultLanguage,
                    'content' => [],
                    'settings' => $attributes['settings'] ?? [],
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

    public function moveItems(array $orderedIds, ?string $parentId, ?string $afterId, Space $space): array
    {
        return DB::transaction(function () use ($orderedIds, $parentId, $afterId, $space): array {
            $warnings = [];
            $normalizedIds = $this->resolveOrderedRootSelection($orderedIds);
            $items = Content::query()
                ->with(['block'])
                ->whereIn('id', $normalizedIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $orderedItems = collect($normalizedIds)
                ->map(fn(string $id) => $items->get($id))
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
                ->reject(fn(string $languageIso) => $languageIso === $defaultLanguage)
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
                if ($targetParent && !$translatedParent) {
                    $warnings[] = $this->makeWarning(
                        'missing-parent-translation',
                        $languageIso,
                        null,
                        'Skipped a translated move because the destination parent does not exist in that language.',
                    );
                    continue;
                }

                $translatedAfter = $after ? $afterFamily->get($languageIso) : null;
                if ($after && !$translatedAfter) {
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
            }

            $space->touch('content_updated_at');

            return [
                'warnings' => $warnings,
            ];
        });
    }

    public function deleteSubtrees(array $ids, Space $space): array
    {
        return DB::transaction(function () use ($ids, $space): array {
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
    ): array {
        return DB::transaction(function () use ($orderedIds, $parentId, $afterId, $space, $owner): array {
            $normalizedIds = $this->resolveOrderedRootSelection($orderedIds);
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
                if (!$source) {
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
                $space->touch('content_updated_at');
            }

            return [
                'created' => $createdRoots->map(fn(Content $content) => $content->fresh())->all(),
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
        return DB::transaction(function () use ($content, $blockId, $space, $owner): array {
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

        $selectedIds = collect($ids)->filter(fn(string $id) => $items->has($id))->values();

        return $selectedIds
            ->filter(function (string $id) use ($selectedIds, $items): bool {
                $current = $items->get($id);

                while ($current?->parent_id) {
                    if ($selectedIds->contains($current->parent_id)) {
                        return false;
                    }

                    $current = $items->get($current->parent_id) ?? Content::query()
                        ->whereNull('deleted_at')
                        ->find($current->parent_id);
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

        $copy = new Content();
        $baseSlug = $this->makeCopySlugBase($source->slug);
        $this->createContent->execute(
            [
                'block_id' => $source->block_id,
                'parent_id' => $parentId,
                'name' => $source->name,
                'slug' => $this->makeUniqueSlug($baseSlug, $parentId, $source->language_iso),
                'language_iso' => $source->language_iso,
                'content' => $source->current_version?->content ?? [],
                'settings' => $source->settings?->toArray() ?? [],
            ],
            $copy,
            $space,
            $owner,
        );

        foreach ($source->children as $child) {
            $this->duplicateNodeRecursive($child, $copy->id, $space, $owner);
        }

        return $copy->fresh();
    }

    protected function makeCopySlugBase(string $slug): string
    {
        return Str::finish($slug, '-copy') === $slug ? "{$slug}-2" : "{$slug}-copy";
    }

    protected function makeUniqueSlug(
        string $baseSlug,
        ?string $parentId,
        string $languageIso,
        ?string $ignoreId = null,
    ): string {
        $slug = Str::slug($baseSlug);
        $suffix = 2;

        while (Content::query()
            ->where('parent_id', $parentId)
            ->where('language_iso', $languageIso)
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = Str::slug("{$baseSlug}-{$suffix}");
            $suffix++;
        }

        return $slug;
    }

    protected function reassignParent(Collection $items, ?string $parentId): void
    {
        foreach ($items->unique('id') as $item) {
            $item->forceFill([
                'parent_id' => $parentId,
            ])->save();
        }
    }

    protected function validateBatchPlacement(Collection $items, ?Content $targetParent, Space $space): void
    {
        foreach ($items as $item) {
            $item->loadMissing('block');

            if ($targetParent && $targetParent->id === $item->id) {
                throw new \InvalidArgumentException('A content item cannot become its own parent.');
            }

            if ($targetParent && $this->wouldCreateCircularReference($item, $targetParent)) {
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
        if (!$after) {
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
            if (!isset($cache[$canonicalId])) {
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

    protected function wouldCreateCircularReference(Content $content, Content $newParent): bool
    {
        $current = $newParent;

        while ($current !== null) {
            if ($current->id === $content->id) {
                return true;
            }

            if ($current->parent_id === null) {
                return false;
            }

            $current = $current->relationLoaded('parent')
                ? $current->getRelation('parent')
                : Content::query()->whereNull('deleted_at')->select(['id', 'parent_id'])->find($current->parent_id);
        }

        return false;
    }

    protected function collectCanonicalSubtreeIds(array $ids): array
    {
        $queue = collect($ids)->unique()->values();
        $seen = [];

        while ($queue->isNotEmpty()) {
            $chunk = $queue->splice(0, 100)->all();
            $rows = Content::query()->whereIn('id', $chunk)->whereNull('deleted_at')->get();

            foreach ($rows as $row) {
                $canonicalId = $this->contentI18nService->getCanonicalId($row);
                if (isset($seen[$canonicalId])) {
                    continue;
                }

                $seen[$canonicalId] = true;

                Content::query()
                    ->where(function ($query) use ($canonicalId) {
                        $query->where('id', $canonicalId)->orWhere('i18n_parent_id', $canonicalId);
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
        }

        return array_keys($seen);
    }
}
