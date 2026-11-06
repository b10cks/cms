<?php

namespace App\Services\Ai\Translation;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Ai\Run\ToolError;
use App\Services\Content\ContentI18nService;
use Illuminate\Support\Collection;

/**
 * Maps entries to their translation destinations. Any member of a language
 * family resolves to its canonical (default-language) row as the source; the
 * destination is that family's row in the target locale, or a new row placed
 * under the parent's row in that locale.
 */
class TranslationDestinations
{
    public const SKIP_NOT_FOUND = 'not_found';

    /** A row outside a family in a language other than the default: nothing to translate from. */
    public const SKIP_NOT_IN_DEFAULT_LANGUAGE = 'not_in_default_language';

    public const SKIP_NO_BLOCK = 'no_block';

    public function __construct(private readonly ContentI18nService $i18n) {}

    /**
     * The configured code of a target locale.
     *
     * @param  list<string>|null  $approved  locales the run was approved for; null allows every translation locale
     *
     * @throws ToolError when the locale is unknown, the default language, or not approved
     */
    public function locale(Space $space, string $locale, ?array $approved = null): string
    {
        $default = $space->settings->getDefaultLanguage();
        $translations = array_values(array_filter($space->settings->getEnabledLanguages(), fn (string $code) => $code !== $default));
        $match = collect($translations)->first(fn (string $code) => strcasecmp($code, trim($locale)) === 0);

        if (strcasecmp($default, trim($locale)) === 0) {
            throw new ToolError("\"{$default}\" is the default language: entries are written in it, not translated into it.");
        }

        if ($match === null) {
            throw new ToolError("\"{$locale}\" is not a translation language of this space. Languages: ".(implode(', ', $translations) ?: 'none').'.');
        }

        if ($approved !== null && ! \in_array($match, $approved, true)) {
            throw new ToolError('This run was approved for '.implode(', ', $approved)." only, not \"{$match}\".");
        }

        return $match;
    }

    /**
     * @param  list<string>|null  $approved
     *
     * @throws ToolError when the entry has no destination in that locale
     */
    public function resolve(Space $space, string $id, string $locale, ?array $approved = null): TranslationDestination
    {
        $result = $this->resolveMany($space, [$id], [$locale], $approved);

        if ($result['destinations'] !== []) {
            return $result['destinations'][0];
        }

        throw new ToolError(match ($result['skipped'][0]['reason'] ?? self::SKIP_NOT_FOUND) {
            self::SKIP_NOT_IN_DEFAULT_LANGUAGE => 'This entry is not in the default language and belongs to no family, so there is no source to translate from.',
            self::SKIP_NO_BLOCK => 'This entry has no block.',
            default => 'No content entry with this ID.',
        });
    }

    /**
     * Destinations for every entry and locale, loaded in a fixed number of queries.
     *
     * @param  list<string>  $ids  canonical or translation IDs; duplicates within a family collapse
     * @param  list<string>  $locales
     * @param  list<string>|null  $approved
     * @return array{destinations: list<TranslationDestination>, skipped: list<array{id: string, reason: string}>}
     *
     * @throws ToolError for an invalid locale
     */
    public function resolveMany(Space $space, array $ids, array $locales, ?array $approved = null): array
    {
        $locales = array_values(array_unique(array_map(fn (string $locale) => $this->locale($space, $locale, $approved), $locales)));
        $default = $space->settings->getDefaultLanguage();
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => \is_string($id) && $id !== '')));

        $rows = Content::query()->whereIn('id', $ids)->whereNull('deleted_at')->get(['id', 'i18n_parent_id', 'language_iso'])->keyBy('id');
        $canonicalIds = $rows->map(fn (Content $row) => $row->i18n_parent_id ?: $row->id)->unique()->values()->all();
        $families = $this->families($canonicalIds);
        $parents = $this->parentRows($families, $locales);

        $destinations = [];
        $skipped = [];
        $seen = [];

        foreach ($ids as $id) {
            $row = $rows->get($id);
            $canonicalId = $row ? ($row->i18n_parent_id ?: $row->id) : null;
            $canonical = $canonicalId ? $families->get($canonicalId)?->firstWhere('id', $canonicalId) : null;

            $reason = match (true) {
                $canonical === null => self::SKIP_NOT_FOUND,
                $canonical->language_iso !== $default => self::SKIP_NOT_IN_DEFAULT_LANGUAGE,
                $canonical->block === null => self::SKIP_NO_BLOCK,
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = ['id' => $id, 'reason' => $reason];

                continue;
            }

            if (isset($seen[$canonical->id])) {
                continue;
            }
            $seen[$canonical->id] = true;

            $mode = $this->i18n->resolveEffectiveModeForCanonical($space, $canonical);
            /** @var Collection<int, Content> $family */
            $family = $families->get($canonical->id);

            foreach ($locales as $locale) {
                $destinations[] = new TranslationDestination(
                    source: $canonical,
                    sourceLocale: $default,
                    locale: $locale,
                    translation: $family->first(fn (Content $member) => $member->i18n_parent_id === $canonical->id && $member->language_iso === $locale),
                    parentId: $canonical->parent_id === null ? null : ($parents[$canonical->parent_id][$locale] ?? $canonical->parent_id),
                    mode: $mode,
                );
            }
        }

        return ['destinations' => $destinations, 'skipped' => $skipped];
    }

    /**
     * Canonical rows and their translations with block and current version, keyed by canonical ID.
     *
     * @param  list<string>  $canonicalIds
     * @return Collection<string, Collection<int, Content>>
     */
    private function families(array $canonicalIds): Collection
    {
        if ($canonicalIds === []) {
            return collect();
        }

        return Content::query()
            ->with(['block', 'current_version'])
            ->where(fn ($query) => $query->whereIn('id', $canonicalIds)->orWhereIn('i18n_parent_id', $canonicalIds))
            ->whereNull('deleted_at')
            ->get()
            ->groupBy(fn (Content $content) => $content->i18n_parent_id ?: $content->id);
    }

    /**
     * The language rows of each canonical's parent: parent ID => locale => row ID.
     *
     * @param  Collection<string, Collection<int, Content>>  $families
     * @param  list<string>  $locales
     * @return array<string, array<string, string>>
     */
    private function parentRows(Collection $families, array $locales): array
    {
        $parentIds = $families->map(fn (Collection $family, string $canonicalId) => $family->firstWhere('id', $canonicalId)?->parent_id)
            ->filter()->unique()->values()->all();

        if ($parentIds === [] || $locales === []) {
            return [];
        }

        $parents = Content::query()->whereIn('id', $parentIds)->get(['id', 'i18n_parent_id']);
        $parentCanonical = $parents->mapWithKeys(fn (Content $parent) => [$parent->id => $parent->i18n_parent_id ?: $parent->id]);

        $rows = Content::query()
            ->whereIn('i18n_parent_id', $parentCanonical->values()->unique()->all())
            ->whereIn('language_iso', $locales)
            ->whereNull('deleted_at')
            ->get(['id', 'i18n_parent_id', 'language_iso'])
            ->groupBy('i18n_parent_id');

        $map = [];
        foreach ($parentCanonical as $parentId => $canonicalId) {
            foreach ($rows->get($canonicalId, collect()) as $row) {
                $map[$parentId][$row->language_iso] = $row->id;
            }
        }

        return $map;
    }
}
