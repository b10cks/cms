<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;

class LocalizedContentSlugService extends ContentSlugService
{
    protected Space $space;

    public function __construct(?Space $space = null)
    {
        $this->space = $space ?? request('space');
    }

    /**
     * Update full slug with localization strategy
     */
    public function updateFullSlug(Content $content): ?string
    {
        $oldFullSlug = $content->full_slug;

        $basePath = $this->buildBasePath($content);
        $localizedPath = $this->applyLocalizationStrategy($basePath, $content->language_iso);

        $content->full_slug = $localizedPath;

        return ($oldFullSlug !== $content->full_slug) ? $oldFullSlug : null;
    }

    protected function buildBasePath(Content $content): string
    {
        if (empty($content->parent_id)) {
            return '/' . $content->slug;
        }

        if (!$content->relationLoaded('parent')) {
            $content->load('parent');
        }

        if ($content->parent) {
            $parentBasePath = $this->stripLocaleFromPath($content->parent->full_slug);
            return $parentBasePath . '/' . $content->slug;
        }

        return '/' . $content->slug;
    }

    protected function applyLocalizationStrategy(string $basePath, string $languageIso): string
    {
        $settings = $this->space->settings;
        if ($settings->shouldPrependLocale($languageIso)) {
            return '/' . $languageIso . $basePath;
        }

        return $basePath;
    }

    protected function stripLocaleFromPath(string $path): string
    {
        $enabledLanguages = $this->space->settings->getEnabledLanguages();
        $pattern = '/^\/(' . implode('|', array_keys($enabledLanguages)) . ')(?=\/|$)/';

        return preg_replace($pattern, '', $path) ?: '/';
    }

    public function generateSlugVariations(Content $content): array
    {
        $basePath = $this->buildBasePath($content);
        $variations = [];

        $variations['current'] = $this->applyLocalizationStrategy($basePath, $content->language_iso);

        $variations['always_prepend'] = '/' . $content->language_iso . $basePath;
        $variations['prepend_translations'] = $content->language_iso !== $this->space->settings->getDefaultLanguage()
            ? '/' . $content->language_iso . $basePath
            : $basePath;
        $variations['never'] = $basePath;

        return array_unique($variations);
    }

}
