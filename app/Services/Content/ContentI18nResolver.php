<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Support\Collection;

class ContentI18nResolver
{
    public function __construct(
        private readonly ContentI18nService $contentI18nService,
    ) {
    }

    public function resolve(
        Space $space,
        Content $content,
        string $targetLanguage,
        string $versionScope = 'published',
    ): ResolvedContent {
        $canonical = $this->contentI18nService->getCanonicalContent($content);
        $family = $this->contentI18nService->getFamily($canonical);
        $requestedLanguage = \in_array($targetLanguage, $space->settings->getEnabledLanguages(), true)
            ? $targetLanguage
            : $space->settings->getDefaultLanguage();
        $effectiveMode = $this->contentI18nService->resolveEffectiveMode($space, $canonical);
        $targetContent = $this->contentI18nService->findLanguageContent($family, $canonical, $requestedLanguage);
        $targetVersion = $targetContent ? $this->resolveVersion($targetContent, $versionScope) : null;

        $resolvedLanguage = $requestedLanguage;
        $resolvedRow = $targetContent;

        $fallbackContent = null;
        $fallbackVersion = null;
        $fallbackChain = collect();

        if ($effectiveMode === 'overlay') {
            $fallbackChain = $this->resolveFallbackChain($space, $family, $canonical, $requestedLanguage, $versionScope, $targetContent);
            $fallbackContent = $fallbackChain->first()['content'] ?? null;
            $fallbackVersion = $fallbackChain->first()['version'] ?? null;
        }

        if ($resolvedRow === null) {
            $resolvedRow = $fallbackContent ?? $canonical;
            $resolvedLanguage = $resolvedRow->language_iso;
        }

        $effectiveContent = $effectiveMode === 'overlay'
            ? $this->mergeContentChain($fallbackChain, $targetVersion)
            : ($targetVersion?->content ?? []);

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
    }

    private function resolveFallbackChain(
        Space $space,
        Collection $family,
        Content $canonical,
        string $requestedLanguage,
        string $versionScope,
        ?Content $targetContent,
    ): Collection {
        $fallbacks = collect();
        $visited = [$requestedLanguage => true];
        $fallbackLanguage = $space->settings->getFallbackLanguage($requestedLanguage);

        while ($fallbackLanguage !== null && !isset($visited[$fallbackLanguage])) {
            $visited[$fallbackLanguage] = true;
            $content = $this->contentI18nService->findLanguageContent($family, $canonical, $fallbackLanguage);

            if ($content && $content->id !== $targetContent?->id) {
                $fallbacks->push([
                    'content' => $content,
                    'version' => $this->resolveVersion($content, $versionScope),
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

    private function mergeContentChain(Collection $fallbackChain, ?ContentVersion $targetVersion): array
    {
        $contentChain = $fallbackChain
            ->pluck('version')
            ->filter()
            ->reverse()
            ->push($targetVersion)
            ->filter();

        return $contentChain->reduce(
            fn(array $merged, ContentVersion $version): array => array_replace_recursive($merged, $version->content ?? []),
            []
        );
    }

    private function mergeCollectionChain(Collection $fallbackChain, string $property, ?Collection $target): Collection
    {
        return $fallbackChain
            ->pluck('version')
            ->filter()
            ->reverse()
            ->map(fn(ContentVersion $version): Collection => $version->{$property} ?? collect())
            ->pipe(function (Collection $collections) use ($target): Collection {
                if ($target) {
                    $collections->push($target);
                }

                return $collections;
            })
            ->reduce(
                fn(Collection $merged, Collection $items): Collection => $merged->merge($items),
                collect()
            )
            ->filter()
            ->unique('id')
            ->values();
    }
}
