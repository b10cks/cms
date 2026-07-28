<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Support\Collection;

/**
 * Builds the ancestor trail of a content entry in a given language.
 *
 * Three queries, independent of how deep the tree is: one recursive CTE for the
 * `parent_id` chain, one for the i18n families of every level, and — for the
 * entry itself — whatever the caller already spent finding it. Neither the
 * version payloads nor `searchable_content` are touched unless the caller asks
 * for the resolved content explicitly.
 *
 * Language handling mirrors {@see ContentI18nResolver}: every level is picked
 * from its own i18n family along the requested language's fallback chain, so a
 * trail never mixes a translated child under an untranslated parent by
 * accident — it falls back per level and says so.
 */
class ContentBreadcrumbService
{
    /**
     * Cycle guard for the recursive walk. A tree this deep is a data error, not
     * a breadcrumb.
     */
    public const int MAX_DEPTH = 25;

    /**
     * The chain query only establishes *which* rows are ancestors and in what
     * order. Every rendered column comes from the family query below, which
     * returns each chain row again as a member of its own family.
     */
    private const array CHAIN_COLUMNS = ['id', 'parent_id', 'i18n_parent_id', 'language_iso'];

    /**
     * Every column a breadcrumb level renders. Deliberately without `content`
     * (the legacy inline payload) and `searchable_content`.
     */
    private const array LEVEL_COLUMNS = [
        'id', 'external_id', 'block_id', 'parent_id', 'position', 'name', 'slug',
        'full_slug', 'language_iso', 'i18n_parent_id', 'settings', 'current_version_id',
        'published_version_id', 'published_at', 'first_published_at', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly LocalizedContentSlugService $slugService,
        private readonly ContentI18nResolver $i18nResolver,
    ) {}

    /**
     * @param  string  $versionScope  `published` or `current`
     * @return Collection<int, BreadcrumbLevel> ordered root → entry
     */
    public function build(
        Space $space,
        Content $entry,
        string $language,
        string $versionScope = 'published',
        bool $includeUnpublishedAncestors = false,
        bool $includeSelf = true,
        bool $withTranslations = false,
        bool $withContent = false,
    ): Collection {
        $chain = $this->fetchChain($entry);

        if (! $includeSelf) {
            $chain = $chain->slice(0, -1)->values();
        }

        if ($chain->isEmpty()) {
            return collect();
        }

        // Only the published scope has anything to hide: in the draft scope the
        // caller already holds a management token's view of the tree.
        $publishedOnly = $versionScope === 'published' && ! $includeUnpublishedAncestors;

        $families = $this->fetchFamilies($chain);
        $priority = $this->languagePriority($space, $language);

        $levels = $chain
            ->map(function (Content $chainRow, int $index) use ($families, $priority, $publishedOnly, $language, $entry, $withTranslations, $space): ?BreadcrumbLevel {
                $family = $families->get($this->canonicalId($chainRow), collect());
                $row = $this->pickRow($family, $priority, $publishedOnly);

                // No row in any language the request may see. Dropping the level
                // keeps the trail free of unreleased names and slugs; the depth
                // of the remaining levels still says where the gap was.
                if ($row === null) {
                    return null;
                }

                return new BreadcrumbLevel(
                    row: $row,
                    requestedLanguage: $language,
                    resolvedLanguage: $row->language_iso,
                    depth: $chainRow->getAttribute('chain_depth') ?? $index,
                    isRoot: $chainRow->parent_id === null,
                    // The entry that was asked for — not merely the deepest level
                    // shown, which `include_self=0` would otherwise mislabel.
                    isCurrent: $this->canonicalId($chainRow) === $this->canonicalId($entry),
                    path: $this->slugService->applyLocalizationStrategy($row->full_slug, $language),
                    translations: $withTranslations ? $this->buildTranslations($space, $family, $row) : null,
                );
            })
            ->filter()
            ->values();

        return $withContent ? $this->attachContent($space, $levels, $language, $versionScope) : $levels;
    }

    /**
     * The `parent_id` chain, root first, in one query.
     *
     * A recursive CTE rather than a lookup per level: the trail is short but the
     * endpoint is on the hot delivery path, and `WITH RECURSIVE` is supported by
     * both engines the space databases run on. The soft-delete condition is
     * spelled out because raw SQL sees no global scope.
     *
     * @return Collection<int, Content>
     */
    private function fetchChain(Content $entry): Collection
    {
        if ($entry->parent_id === null) {
            return collect([$this->chainRow($entry, 0)]);
        }

        $seed = implode(', ', array_map(static fn (string $c): string => "s.{$c}", self::CHAIN_COLUMNS));
        $step = implode(', ', array_map(static fn (string $c): string => "c.{$c}", self::CHAIN_COLUMNS));

        $rows = $entry->getConnection()->select(
            <<<SQL
            WITH RECURSIVE breadcrumb_chain AS (
                SELECT {$seed}, 0 AS chain_depth
                FROM contents s
                WHERE s.id = ? AND s.deleted_at IS NULL
                UNION ALL
                SELECT {$step}, bc.chain_depth + 1
                FROM contents c
                INNER JOIN breadcrumb_chain bc ON c.id = bc.parent_id
                WHERE c.deleted_at IS NULL AND bc.chain_depth < ?
            )
            SELECT * FROM breadcrumb_chain
            SQL,
            [$entry->id, self::MAX_DEPTH],
        );

        // `chain_depth` counts *up* from the entry; the trail reads down from the
        // root, so both the order and the reported depth are inverted here.
        $maxDepth = 0;
        foreach ($rows as $row) {
            $maxDepth = max($maxDepth, (int) $row->chain_depth);
        }

        return collect($rows)
            ->sortByDesc('chain_depth')
            ->values()
            ->map(function (object $row) use ($maxDepth): Content {
                $model = (new Content)->newFromBuilder((array) $row);
                $model->setAttribute('chain_depth', $maxDepth - (int) $row->chain_depth);

                return $model;
            });
    }

    private function chainRow(Content $entry, int $depth): Content
    {
        $model = (new Content)->newFromBuilder(
            collect($entry->getAttributes())->only(self::CHAIN_COLUMNS)->all()
        );
        $model->setAttribute('chain_depth', $depth);

        return $model;
    }

    /**
     * Every i18n family touched by the trail, in one query.
     *
     * Each chain row is itself a member of one of these families, so this also
     * supplies the columns the chain query left out. The block slug is joined
     * rather than eager loaded to keep the whole endpoint at three queries.
     *
     * @param  Collection<int, Content>  $chain
     * @return Collection<string, Collection<int, Content>> keyed by canonical id
     */
    private function fetchFamilies(Collection $chain): Collection
    {
        $canonicalIds = $chain->map(fn (Content $row): string => $this->canonicalId($row))->unique()->values();

        return Content::query()
            ->select([
                ...array_map(static fn (string $c): string => "contents.{$c}", self::LEVEL_COLUMNS),
                'blocks.slug as block_slug',
            ])
            ->leftJoin('blocks', 'contents.block_id', '=', 'blocks.id')
            ->where(function ($query) use ($canonicalIds): void {
                $query->whereIn('contents.id', $canonicalIds)
                    ->orWhereIn('contents.i18n_parent_id', $canonicalIds);
            })
            ->get()
            ->groupBy(fn (Content $content): string => $this->canonicalId($content));
    }

    /**
     * The languages a level may be served from, best first.
     *
     * Same walk as {@see ContentI18nResolver}: the requested language, then its
     * configured fallbacks, ending at the default language — which is appended
     * even when no fallback chain leads there, because the canonical row is the
     * last thing a breadcrumb can still show.
     *
     * @return array<int, string>
     */
    private function languagePriority(Space $space, string $language): array
    {
        $default = $space->settings->getDefaultLanguage();
        $priority = [$language];
        $visited = [$language => true];

        $next = $space->settings->getFallbackLanguage($language);
        while ($next !== null && ! isset($visited[$next])) {
            $visited[$next] = true;
            $priority[] = $next;

            if ($next === $default) {
                break;
            }

            $next = $space->settings->getFallbackLanguage($next);
        }

        if (! isset($visited[$default])) {
            $priority[] = $default;
        }

        return $priority;
    }

    /**
     * @param  Collection<int, Content>  $family
     * @param  array<int, string>  $priority
     */
    private function pickRow(Collection $family, array $priority, bool $publishedOnly): ?Content
    {
        foreach ($priority as $languageIso) {
            $candidate = $family->firstWhere('language_iso', $languageIso);

            if ($candidate && (! $publishedOnly || $this->isPublished($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Both columns, for the same reason {@see Content::scopePublished()} checks
     * both: `published_version_id` survives an unpublish.
     */
    private function isPublished(Content $content): bool
    {
        return $content->published_at !== null && $content->published_version_id !== null;
    }

    /**
     * @param  Collection<int, Content>  $family
     * @return array<int, array{language_iso: string, name: string|null, full_slug: string, path: string}>
     */
    private function buildTranslations(Space $space, Collection $family, Content $row): array
    {
        return $family
            ->filter(fn (Content $sibling): bool => $sibling->id !== $row->id && $this->isPublished($sibling))
            ->filter(fn (Content $sibling): bool => \in_array($sibling->language_iso, $space->settings->getEnabledLanguages(), true))
            ->map(fn (Content $sibling): array => [
                'language_iso' => $sibling->language_iso,
                'name' => $sibling->name,
                'full_slug' => $sibling->full_slug,
                'path' => $this->slugService->applyLocalizationStrategy($sibling->full_slug, $sibling->language_iso),
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve the overlay-merged payload for every level in one batch.
     *
     * Opt-in: this is what pulls the version rows — and their assets, links and
     * relations — into a request that otherwise never reads a payload at all.
     *
     * @param  Collection<int, BreadcrumbLevel>  $levels
     * @return Collection<int, BreadcrumbLevel>
     */
    private function attachContent(Space $space, Collection $levels, string $language, string $versionScope): Collection
    {
        if ($levels->isEmpty()) {
            return $levels;
        }

        $resolved = $this->i18nResolver->resolveMany(
            $space,
            $levels->map(fn (BreadcrumbLevel $level): array => [
                'content' => $level->row,
                'target_language' => $language,
            ]),
            $versionScope,
        )->values();

        return $levels->map(fn (BreadcrumbLevel $level, int $index): BreadcrumbLevel => new BreadcrumbLevel(
            row: $level->row,
            requestedLanguage: $level->requestedLanguage,
            resolvedLanguage: $level->resolvedLanguage,
            depth: $level->depth,
            isRoot: $level->isRoot,
            isCurrent: $level->isCurrent,
            path: $level->path,
            translations: $level->translations,
            resolved: $resolved->get($index),
        ));
    }

    private function canonicalId(Content $content): string
    {
        return $content->i18n_parent_id ?: $content->id;
    }
}
