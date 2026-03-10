<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;

class LocalizedContentSlugService extends ContentSlugService
{
    protected Space $space;

    public function __construct(?Space $space = null)
    {
        $this->space = $space ?? request('space') ?? app('currentSpace');
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
            $parentBasePath = $this->stripLocaleFromPath($content->parent->full_slug);
            return "{$parentBasePath}/{$content->slug}";
        }

        return "/{$content->slug}";
    }

    public function applyLocalizationStrategy(string $basePath, string $languageIso): string
    {
        if ($this->space->settings->shouldPrependLocale($languageIso)) {
            return "/{$languageIso}{$basePath}";
        }

        return $basePath;
    }

    public function formatRedirectSlug(string $basePath, string $languageIso): string
    {
        return $this->applyLocalizationStrategy($basePath, $languageIso);
    }

    protected function stripLocaleFromPath(string $path): string
    {
        $enabledLanguages = $this->space->settings->getEnabledLanguages();
        $pattern = '/^\/(' . implode('|', $enabledLanguages) . ')(?=\/|$)/';

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

        return array_unique($variations);
    }

}
