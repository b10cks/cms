<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Support\Collection;

class ContentI18nService
{
    public function getCanonicalId(Content $content): string
    {
        return $content->i18n_parent_id ?: $content->id;
    }

    public function getCanonicalContent(Content $content): Content
    {
        if ($content->i18n_parent_id === null) {
            return $content;
        }

        return Content::query()->findOrFail($content->i18n_parent_id);
    }

    public function getFamily(Content $content): Collection
    {
        $canonicalId = $this->getCanonicalId($content);

        return Content::query()
            ->where(function ($query) use ($canonicalId) {
                $query->where('id', $canonicalId)
                    ->orWhere('i18n_parent_id', $canonicalId);
            })
            ->whereNull('deleted_at')
            ->get();
    }

    /**
     * Where a translation in `$languageIso` belongs when filed under `$parentId`:
     * that parent's own translation in the language, or the given parent while
     * none exists. Tree moves follow the same rule.
     */
    public function parentForLanguage(?string $parentId, string $languageIso): ?string
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Content::query()->select(['id', 'i18n_parent_id', 'language_iso'])->find($parentId);

        if (! $parent || $parent->language_iso === $languageIso) {
            return $parentId;
        }

        $canonicalId = $this->getCanonicalId($parent);

        return Content::query()
            ->where(fn ($query) => $query->where('id', $canonicalId)->orWhere('i18n_parent_id', $canonicalId))
            ->where('language_iso', $languageIso)
            ->value('id') ?? $parentId;
    }

    /**
     * Move the translations in the new translation's language that were filed
     * under its canonical entry, because it did not exist yet, under the new
     * translation. Their full_slug follows through the slug propagation job.
     *
     * @return int The number of re-homed entries
     */
    public function adoptTranslatedChildren(Content $translation): int
    {
        if ($translation->i18n_parent_id === null) {
            return 0;
        }

        return Content::query()
            ->where('parent_id', $translation->i18n_parent_id)
            ->where('language_iso', $translation->language_iso)
            ->whereNotNull('i18n_parent_id')
            ->update(['parent_id' => $translation->id]);
    }

    public function findLanguageContent(Collection $family, Content $canonical, string $languageIso): ?Content
    {
        if ($languageIso === $canonical->language_iso) {
            return $family->firstWhere('id', $canonical->id) ?? $canonical;
        }

        return $family->firstWhere('language_iso', $languageIso);
    }

    public function resolveEffectiveMode(Space $space, Content $content): string
    {
        $canonical = $this->getCanonicalContent($content);

        return $this->resolveEffectiveModeForCanonical($space, $canonical);
    }

    public function buildLanguageVersions(Space $space, Content $content): array
    {
        $canonical = $this->getCanonicalContent($content);
        $family = $this->getFamily($canonical);

        return $this->buildLanguageVersionsFromFamily($space, $content, $family, $canonical);
    }

    public function resolveEffectiveModeForCanonical(Space $space, Content $canonical): string
    {
        $override = data_get($canonical->settings?->toArray() ?? [], 'i18n_mode_override');

        return \in_array($override, ['overlay', 'independent'], true)
            ? $override
            : $space->settings->getI18nMode();
    }

    public function buildLanguageVersionsFromFamily(
        Space $space,
        Content $content,
        Collection $family,
        ?Content $canonical = null,
    ): array {
        $canonical ??= $family->firstWhere('id', $this->getCanonicalId($content)) ?? $this->getCanonicalContent($content);
        $familyByLanguage = $family->keyBy('language_iso');
        $defaultLanguage = $space->settings->getDefaultLanguage();

        return array_map(function (string $languageIso) use ($space, $content, $canonical, $familyByLanguage, $defaultLanguage): array {
            /** @var Content|null $row */
            $row = $familyByLanguage->get($languageIso);

            return [
                'language_iso' => $languageIso,
                'label' => $languageIso === $defaultLanguage ? 'Default' : $space->settings->getLanguageLabel($languageIso),
                'exists' => $row !== null,
                'content_id' => $row?->id,
                'is_default' => $languageIso === $defaultLanguage,
                // The version the entry was created in. Usually the default
                // language, but an entry can start in any enabled language.
                'is_canonical' => $row !== null && $row->id === $canonical->id,
                'is_current' => $content->language_iso === $languageIso,
                // Same three states the content header shows: live, live with a
                // draft staged on top, or not live at all.
                'status' => match (true) {
                    $row === null => 'missing',
                    $row->published_at === null => 'draft',
                    $row->current_version_id !== $row->published_version_id => 'changed',
                    default => 'published',
                },
                'published_at' => $row?->published_at?->toIso8601String(),
                'fallback_language' => $space->settings->getFallbackLanguage($languageIso),
            ];
        }, $space->settings->getEnabledLanguages());
    }
}
