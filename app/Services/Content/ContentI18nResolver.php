<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Content\Schema\ContentSchemaValueMerger;
use Illuminate\Support\Collection;

class ContentI18nResolver
{
    public function __construct(
        private readonly ContentI18nService $contentI18nService,
        private readonly ContentSchemaValueMerger $contentSchemaValueMerger,
    ) {}

    public function resolve(
        Space $space,
        Content $content,
        string $targetLanguage,
        string $versionScope = 'published',
    ): ResolvedContent {
        return $this->resolveMany(
            $space,
            collect([
                [
                    'content' => $content,
                    'target_language' => $targetLanguage,
                ],
            ]),
            $versionScope,
        )->first();
    }

    public function resolveMany(
        Space $space,
        Collection $items,
        string $versionScope = 'published',
    ): Collection {
        if ($items->isEmpty()) {
            return collect();
        }

        $normalizedItems = $items->map(function ($item) {
            if ($item instanceof Content) {
                return [
                    'content' => $item,
                    'target_language' => $item->language_iso,
                ];
            }

            return $item;
        });

        $contents = $normalizedItems
            ->pluck('content')
            ->filter();

        $canonicalsById = $this->resolveCanonicals($contents);
        $familiesByCanonicalId = $this->resolveFamilies($canonicalsById);
        $effectiveModesByCanonicalId = $canonicalsById->mapWithKeys(
            fn (Content $canonical, string $canonicalId): array => [
                $canonicalId => $this->resolveEffectiveModeForCanonical($space, $canonical),
            ]
        );
        $versionsByContentId = $this->resolveVersionsForFamilies($familiesByCanonicalId, $versionScope);

        return $normalizedItems->map(function (array $item) use ($space, $canonicalsById, $familiesByCanonicalId, $effectiveModesByCanonicalId, $versionsByContentId): ResolvedContent {
            /** @var Content $content */
            $content = $item['content'];
            $requestedLanguage = \in_array($item['target_language'], $space->settings->getEnabledLanguages(), true)
                ? $item['target_language']
                : $space->settings->getDefaultLanguage();

            $canonicalId = $this->contentI18nService->getCanonicalId($content);
            /** @var Content $canonical */
            $canonical = $canonicalsById->get($canonicalId, $content);
            /** @var Collection $family */
            $family = $familiesByCanonicalId->get($canonical->id, collect([$canonical]));
            $contentByLanguage = $family->keyBy('language_iso');

            $resolvedCanonical = $content->language_iso === $canonical->language_iso
                ? $content
                : $contentByLanguage->get($canonical->language_iso, $canonical);

            if ($resolvedCanonical->relationLoaded('block') && ! $canonical->relationLoaded('block')) {
                $canonical->setRelation('block', $resolvedCanonical->getRelation('block'));
            }

            foreach (['i18n_parent', 'i18n_children', 'i18n_siblings', 'relations', 'assets', 'links'] as $relation) {
                if ($content->relationLoaded($relation)) {
                    $resolvedCanonical->setRelation($relation, $content->getRelation($relation));
                }
            }

            $family = $family->map(function (Content $familyContent) use ($contentByLanguage, $content): Content {
                $resolvedFamilyContent = $contentByLanguage->get($familyContent->language_iso, $familyContent);

                foreach (['block', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'relations', 'assets', 'links'] as $relation) {
                    if ($resolvedFamilyContent->relationLoaded($relation)) {
                        $familyContent->setRelation($relation, $resolvedFamilyContent->getRelation($relation));
                    }
                }

                if ($familyContent->id === $content->id) {
                    foreach (['block', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'relations', 'assets', 'links'] as $relation) {
                        if ($content->relationLoaded($relation)) {
                            $familyContent->setRelation($relation, $content->getRelation($relation));
                        }
                    }
                }

                return $familyContent;
            })->values();

            $effectiveMode = $effectiveModesByCanonicalId->get($canonical->id, $space->settings->getI18nMode());
            $targetContent = $this->contentI18nService->findLanguageContent($family, $resolvedCanonical, $requestedLanguage);
            $targetVersion = $targetContent ? $versionsByContentId->get($targetContent->id) : null;
            $blockSchema = $resolvedCanonical->block?->schema?->toArray() ?? [];

            $resolvedLanguage = $requestedLanguage;
            $resolvedRow = $targetContent;

            $fallbackContent = null;
            $fallbackVersion = null;
            $fallbackChain = collect();

            if ($effectiveMode === 'overlay') {
                $fallbackChain = $this->resolveFallbackChainFromPreloaded(
                    $space,
                    $family,
                    $canonical,
                    $requestedLanguage,
                    $versionsByContentId,
                    $targetContent,
                );
                $fallbackContent = $fallbackChain->first()['content'] ?? null;
                $fallbackVersion = $fallbackChain->first()['version'] ?? null;
            }

            if ($resolvedRow === null) {
                $resolvedRow = $fallbackContent ?? $canonical;
                $resolvedLanguage = $resolvedRow->language_iso;
            }

            $effectiveContent = $effectiveMode === 'overlay'
                ? $this->mergeContentChain($fallbackChain, $targetVersion, $blockSchema, $resolvedCanonical)
                : ($targetVersion?->content ?? $content->content ?? []);

            return new ResolvedContent(
                canonicalContent: $canonical,
                familyContents: $family,
                requestedLanguage: $requestedLanguage,
                resolvedLanguage: $resolvedLanguage,
                effectiveMode: $effectiveMode,
                resolvedRow: $resolvedRow,
                targetContent: $targetContent,
                targetVersion: $targetVersion,
                fallbackContent: $fallbackContent,
                fallbackVersion: $fallbackVersion,
                effectiveContent: $effectiveContent,
                effectiveAssets: $effectiveMode === 'overlay'
                ? $this->mergeCollectionChain($fallbackChain, 'assets', $targetVersion?->assets)
                : collect($targetVersion?->assets ?? []),
                effectiveLinks: $effectiveMode === 'overlay'
                ? $this->mergeCollectionChain($fallbackChain, 'links', $targetVersion?->links)
                : collect($targetVersion?->links ?? []),
                effectiveRelations: $effectiveMode === 'overlay'
                ? $this->mergeCollectionChain($fallbackChain, 'relations', $targetVersion?->relations)
                : collect($targetVersion?->relations ?? []),
            );
        })->values();
    }

    private function resolveFallbackChain(
        Space $space,
        Collection $family,
        Content $canonical,
        string $requestedLanguage,
        string $versionScope,
        ?Content $targetContent,
    ): Collection {
        $versionsByContentId = $this->resolveVersionsForFamilies(
            collect([$family->keyBy('id')]),
            $versionScope,
        );

        return $this->resolveFallbackChainFromPreloaded(
            $space,
            $family,
            $canonical,
            $requestedLanguage,
            $versionsByContentId,
            $targetContent,
        );
    }

    private function resolveFallbackChainFromPreloaded(
        Space $space,
        Collection $family,
        Content $canonical,
        string $requestedLanguage,
        Collection $versionsByContentId,
        ?Content $targetContent,
    ): Collection {
        $fallbacks = collect();
        $visited = [$requestedLanguage => true];
        $fallbackLanguage = $space->settings->getFallbackLanguage($requestedLanguage);

        while ($fallbackLanguage !== null && ! isset($visited[$fallbackLanguage])) {
            $visited[$fallbackLanguage] = true;
            $content = $this->contentI18nService->findLanguageContent($family, $canonical, $fallbackLanguage);

            if ($content && $content->id !== $targetContent?->id) {
                $fallbacks->push([
                    'content' => $content,
                    'version' => $versionsByContentId->get($content->id),
                ]);
            }

            if ($fallbackLanguage === $space->settings->getDefaultLanguage()) {
                break;
            }

            $fallbackLanguage = $space->settings->getFallbackLanguage($fallbackLanguage);
        }

        return $fallbacks;
    }

    private function resolveVersion(Content $content, string $versionScope): ?ContentVersion
    {
        if ($versionScope === 'published') {
            $content->loadMissing([
                'published_version.assets',
                'published_version.links',
                'published_version.relations',
            ]);

            return $content->published_version;
        }

        if ($versionScope === 'current' || $versionScope === 'draft') {
            $content->loadMissing([
                'current_version.assets',
                'current_version.links',
                'current_version.relations',
            ]);

            return $content->current_version;
        }

        return ContentVersion::query()
            ->where('content_id', $content->id)
            ->where('id', $versionScope)
            ->with(['assets', 'links', 'relations'])
            ->first();
    }

    private function resolveCanonicals(Collection $contents): Collection
    {
        $canonicalIds = $contents
            ->map(fn (Content $content): string => $this->contentI18nService->getCanonicalId($content))
            ->unique()
            ->values();

        $alreadyLoadedCanonicals = $contents
            ->filter(fn (Content $content): bool => $content->i18n_parent_id === null)
            ->keyBy('id');

        $missingCanonicalIds = $canonicalIds
            ->reject(fn (string $canonicalId): bool => $alreadyLoadedCanonicals->has($canonicalId))
            ->values();

        $loadedCanonicals = $missingCanonicalIds->isEmpty()
            ? collect()
            : Content::query()
                ->whereIn('id', $missingCanonicalIds)
                ->whereNull('deleted_at')
                ->with('block')
                ->get()
                ->keyBy('id');

        return $alreadyLoadedCanonicals->merge($loadedCanonicals);
    }

    private function resolveFamilies(Collection $canonicalsById): Collection
    {
        $canonicalIds = $canonicalsById->keys()->values();

        if ($canonicalIds->isEmpty()) {
            return collect();
        }

        return Content::query()
            ->where(function ($query) use ($canonicalIds) {
                $query->whereIn('id', $canonicalIds)
                    ->orWhereIn('i18n_parent_id', $canonicalIds);
            })
            ->whereNull('deleted_at')
            ->with('block')
            ->get()
            ->groupBy(fn (Content $content): string => $content->i18n_parent_id ?: $content->id);
    }

    private function resolveEffectiveModeForCanonical(Space $space, Content $canonical): string
    {
        $override = data_get($canonical->settings?->toArray() ?? [], 'i18n_mode_override');

        return \in_array($override, ['overlay', 'independent'], true)
            ? $override
            : $space->settings->getI18nMode();
    }

    private function resolveVersionsForFamilies(Collection $familiesByCanonicalId, string $versionScope): Collection
    {
        $familyContents = new \Illuminate\Database\Eloquent\Collection(
            $familiesByCanonicalId
                ->flatMap(fn (Collection $family): Collection => $family)
                ->keyBy('id')
                ->all()
        );

        if ($familyContents->isEmpty()) {
            return collect();
        }

        if ($versionScope === 'published') {
            $familyContents->load([
                'published_version.assets',
                'published_version.links',
                'published_version.relations',
            ]);

            return $familyContents->mapWithKeys(
                fn (Content $content): array => [$content->id => $content->published_version]
            );
        }

        if ($versionScope === 'current' || $versionScope === 'draft') {
            $familyContents->load([
                'current_version.assets',
                'current_version.links',
                'current_version.relations',
            ]);

            return $familyContents->mapWithKeys(
                fn (Content $content): array => [$content->id => $content->current_version]
            );
        }

        return ContentVersion::query()
            ->whereIn('content_id', $familyContents->modelKeys())
            ->where('id', $versionScope)
            ->with(['assets', 'links', 'relations'])
            ->get()
            ->keyBy('content_id');
    }

    private function mergeContentChain(
        Collection $fallbackChain,
        ?ContentVersion $targetVersion,
        array $blockSchema,
        ?Content $selectedContent = null,
    ): array {
        $contentChain = $fallbackChain
            ->pluck('version')
            ->filter()
            ->reverse();

        $merged = $targetVersion ? [] : $this->resolveContentPayload($selectedContent);

        foreach ($contentChain as $version) {
            $merged = $this->contentSchemaValueMerger->mergeForSchema(
                $blockSchema,
                $merged,
                $version->content ?? [],
                true,
            );
        }

        if ($targetVersion) {
            return $this->contentSchemaValueMerger->mergeForSchema(
                $blockSchema,
                $merged,
                $targetVersion->content ?? [],
                true,
            );
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContentPayload(?Content $content): array
    {
        if (! $content) {
            return [];
        }

        $currentContent = $content->relationLoaded('current_version')
            ? $content->current_version?->content
            : null;

        if (\is_array($currentContent)) {
            return $currentContent;
        }

        $publishedContent = $content->relationLoaded('published_version')
            ? $content->published_version?->content
            : null;

        if (\is_array($publishedContent)) {
            return $publishedContent;
        }

        if (\is_array($content->content ?? null)) {
            return $content->content;
        }

        return $content->getContent();
    }

    private function mergeCollectionChain(Collection $fallbackChain, string $property, ?Collection $target): Collection
    {
        return $fallbackChain
            ->pluck('version')
            ->filter()
            ->reverse()
            ->map(fn (ContentVersion $version): Collection => $version->{$property} ?? collect())
            ->pipe(function (Collection $collections) use ($target): Collection {
                if ($target) {
                    $collections->push($target);
                }

                return $collections;
            })
            ->reduce(
                fn (Collection $merged, Collection $items): Collection => $merged->merge($items),
                collect()
            )
            ->filter()
            ->unique('id')
            ->values();
    }
}
