<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Support\SpaceContext;

class LocalizedContentSlugService extends ContentSlugService
{
    protected Space $space;

    public function __construct(?Space $space = null)
    {
        $this->space = $space ?? request('space') ?? SpaceContext::current();
    }

    public function updateFullSlug(Content $content): ?string
    {
        $oldFullSlug = $content->full_slug;

        $content->full_slug = $this->buildBasePath($content);

        return ($oldFullSlug !== $content->full_slug) ? $oldFullSlug : null;
    }

    protected function buildBasePath(Content $content): string
    {
        if (empty($content->parent_id)) {
            return "/{$content->slug}";
        }

        if (!$content->relationLoaded('parent')) {
            $content->load('parent');
        }

        if ($content->parent) {
            $parent = $this->resolveTranslatedParent($content->parent, $content->language_iso);
            $parentBasePath = $this->stripLocaleFromPath($parent->full_slug);
            return "{$parentBasePath}/{$content->slug}";
        }

        return "/{$content->slug}";
    }

    protected function resolveTranslatedParent(Content $parent, string $languageIso): Content
    {
        if ($parent->language_iso === $languageIso) {
            return $parent;
        }

        $canonicalId = $parent->i18n_parent_id ?? $parent->id;

        $translatedParent = Content::query()
            ->where('i18n_parent_id', $canonicalId)
            ->where('language_iso', $languageIso)
            ->whereNull('deleted_at')
            ->first();

        return $translatedParent ?? $parent;
    }

    protected function formatChildRedirectSlug(string $basePath, Content $child): string
    {
        return $this->formatRedirectSlug($basePath, $child->language_iso);
    }

    public function applyLocalizationStrategy(string $basePath, string $languageIso): string
    {
        $segment = $this->space->settings->getLocaleSegment($languageIso);

        if ($segment !== '') {
            return "/{$segment}{$basePath}";
        }

        return $basePath;
    }

    public function formatRedirectSlug(string $basePath, string $languageIso): string
    {
        return $this->applyLocalizationStrategy($basePath, $languageIso);
    }

    protected function stripLocaleFromPath(string $path): string
    {
        $segments = $this->space->settings->getEnabledLanguages();

        foreach ($this->space->settings->getSiteLocales() as $locale) {
            $segments[] = trim((string) $locale['segment'], '/');
        }

        $segments = array_unique(array_map(fn (string $s): string => preg_quote($s, '/'), $segments));
        $pattern = '/^\/(' . implode('|', $segments) . ')(?=\/|$)/';

        return preg_replace($pattern, '', $path) ?: '/';
    }

    public function generateSlugVariations(Content $content): array
    {
        $basePath = $this->buildBasePath($content);
        $variations = [];

        $variations['current'] = $basePath;
        $variations['always_prepend'] = "/{$content->language_iso}{$basePath}";
        $variations['prepend_translations'] = $content->language_iso !== $this->space->settings->getDefaultLanguage()
            ? "/{$content->language_iso}{$basePath}"
            : $basePath;
        $variations['never'] = $basePath;

        foreach ($this->space->settings->getSegmentsForLanguage($content->language_iso) as $index => $segment) {
            $variations["segment_{$index}"] = "/{$segment}{$basePath}";
        }

        return array_unique($variations);
    }

}
