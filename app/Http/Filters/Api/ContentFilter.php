<?php

namespace App\Http\Filters\Api;

use App\Models\Space\Block;
use CodersCantina\Filter\AdvancedFilter;

/**
 * Public content filters for the Data API.
 *
 * Supported filter syntax:
 * - Exact value: `?language=en`
 * - Ranges: `?created_at=2024-01-01...2024-01-31`
 * - Comparisons: `?published_at=>=2024-01-01T00:00:00Z`
 *
 * @filterDescription Filters the content collection by language, content type, parent relation, and timestamps.
 *
 * @sortDescription Sort by a supported field. Prefix with `-` for descending order.
 */
class ContentFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['published_at', 'updated_at', 'created_at'];

    /**
     * Extends default sorting with support for `content.{field}` which extracts
     * a top-level JSON key from `content_versions.content`.
     *
     * Example: `?sort=content.publishedAt` or `?sort=-content.publishedAt`
     *
     * Only one level of nesting is allowed; the field name must match [a-zA-Z0-9_]+.
     */
    public function sort($sortString = null): void
    {
        if (!$sortString || !is_string($sortString)) {
            return;
        }

        $regularSortItems = [];
        $sortItems = array_slice(explode(',', $sortString), 0, $this->maxSortColumns);

        foreach ($sortItems as $sortItem) {
            $direction = str_starts_with($sortItem, '-') ? 'desc' : 'asc';
            $bare = ltrim($sortItem, '+-');

            // content.{field} -> JSON_EXTRACT from content_versions.content (one level deep only)
            if (preg_match('/^content\.([a-zA-Z0-9_]+)$/', $bare, $matches)) {
                $this->builder->orderByRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(`content_versions`.`content`, ?)) {$direction}",
                    ['$.' . $matches[1]],
                );
                continue;
            }

            $regularSortItems[] = $sortItem;
        }

        if (!empty($regularSortItems)) {
            parent::sort(implode(',', $regularSortItems));
        }
    }

    /**
     * Filter by publication timestamp.
     *
     * Supports:
     * - exact ISO-8601 datetime
     * - ranges: `from...to`
     * - operators: `>=value`, `>value`, `<=value`, `<value`, `<>value`
     *
     * @filterDescription Filter by publication date and time.
     *
     * @filterType string
     *
     * @filterFormat date-time
     *
     * @filterExample >=2024-01-01T00:00:00Z
     */
    public function published_at($value)
    {
        $this->applyAdvancedRangeFilter('contents.published_at', $value);
    }

    /**
     * Filter by last update timestamp.
     *
     * Supports:
     * - exact ISO-8601 datetime
     * - ranges: `from...to`
     * - operators: `>=value`, `>value`, `<=value`, `<value`, `<>value`
     *
     * @filterDescription Filter by last update date and time.
     *
     * @filterType string
     *
     * @filterFormat date-time
     *
     * @filterExample 2024-01-01T00:00:00Z...2024-01-31T23:59:59Z
     */
    public function updated_at($value)
    {
        $this->applyAdvancedRangeFilter('contents.updated_at', $value);
    }

    /**
     * Filter by creation timestamp.
     *
     * Supports:
     * - exact ISO-8601 datetime
     * - ranges: `from...to`
     * - operators: `>=value`, `>value`, `<=value`, `<value`, `<>value`
     *
     * @filterDescription Filter by creation date and time.
     *
     * @filterType string
     *
     * @filterFormat date-time
     *
     * @filterExample <2024-06-01T00:00:00Z
     */
    public function created_at($value)
    {
        $this->applyAdvancedRangeFilter('contents.created_at', $value);
    }

    /**
     * Filter by requested language ISO code.
     *
     * Example:
     * - `?language=en`
     *
     * @filterDescription Filter by content language ISO code.
     *
     * @filterType string
     *
     * @filterExample en
     */
    public function language($value)
    {
        if (filter_var($this->filters['include_fallback'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $this->builder->where('contents.language_iso', $value);
    }

    /**
     * Filter by requested language ISO code using the explicit column name.
     *
     * Example:
     * - `?language_iso=en`
     *
     * @filterDescription Filter by content language ISO code.
     *
     * @filterType string
     *
     * @filterExample en
     */
    public function language_iso($value)
    {
        if (filter_var($this->filters['include_fallback'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $this->builder->where('contents.language_iso', $value);
    }

    /**
     * Filter by block type.
     *
     * This resolves matching blocks first and then limits content to those block IDs.
     *
     * Example:
     * - `?content_type=page`
     *
     * @filterDescription Filter by block type slug used by the content entry.
     *
     * @filterType string
     *
     * @filterExample page
     */
    public function content_type($value)
    {
        $this->builder->whereIn('contents.block_id', Block::query()->where('slug', $value)->select('id'));
    }

    /**
     * Filter by parent content ID.
     *
     * Example:
     * - `?parent_id=01J123ABC456DEF789GHIJKLMN`
     *
     * @filterDescription Filter by parent content identifier.
     *
     * @filterType string
     *
     * @filterExample 01J123ABC456DEF789GHIJKLMN
     */
    public function parent_id($value)
    {
        $this->applyDynamicFilter('contents.parent_id', $value);
    }

    /**
     * Filter by content ID.
     *
     * Example:
     * - `?id=01J123ABC456DEF789GHIJKLMN`
     *
     * @filterDescription Filter by content identifier.
     *
     * @filterType string
     *
     * @filterExample 01J123ABC456DEF789GHIJKLMN
     */
    public function id($value)
    {
        $this->applyDynamicFilter('contents.id', $value);
    }

    /**
     * Filter by canonical content ID, matching the canonical row and all its translations.
     *
     * Use this instead of `id` when you have the canonical content's ID and want to
     * retrieve a specific translated variant by combining with `?language=de`.
     *
     * Example:
     * - `?canonical_id=01J123ABC456DEF789GHIJKLMN&language=de`
     *
     * @filterDescription Filter by canonical content identifier, including all translations.
     *
     * @filterType string
     *
     * @filterExample 01J123ABC456DEF789GHIJKLMN
     */
    public function canonical_id($value)
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('contents.id', $value)
                ->orWhere('contents.i18n_parent_id', $value);
        });
    }

    /**
     * Filter by canonical parent content ID, matching children of the canonical and all its translations.
     *
     * Use this instead of `parent_id` when you have the canonical parent's ID and want
     * children in a specific language by combining with `?language=de`.
     *
     * Example:
     * - `?canonical_parent_id=01J123ABC456DEF789GHIJKLMN&language=de`
     *
     * @filterDescription Filter by canonical parent identifier, including children of translated parent variants.
     *
     * @filterType string
     *
     * @filterExample 01J123ABC456DEF789GHIJKLMN
     */
    public function canonical_parent_id($value)
    {
        $this->builder->whereIn('contents.parent_id', function ($query) use ($value) {
            $query->select('id')
                ->from('contents')
                ->where(function ($q) use ($value) {
                    $q->where('id', $value)
                        ->orWhere('i18n_parent_id', $value);
                })
                ->whereNull('deleted_at');
        });
    }

    /**
     * When combined with a `language` or `language_iso` filter, also include canonical
     * rows that have no translation in the requested language. The i18n resolver will
     * apply overlay/fallback logic to these rows so they are still returned in the
     * response for the requested language.
     *
     * Without this flag, `?language=de` only returns rows where `language_iso = de`,
     * silently omitting content that would be served via fallback.
     *
     * Example:
     * - `?language=de&include_fallback=true`
     *
     * @filterDescription Include canonical content without a translation in the requested language.
     *
     * @filterType boolean
     *
     * @filterExample true
     */
    public function include_fallback($value)
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $requestedLanguage = $this->filters['language'] ?? $this->filters['language_iso'] ?? null;

        if (! $requestedLanguage) {
            return;
        }

        $this->builder->where(function ($query) use ($requestedLanguage) {
            $query->where('contents.language_iso', $requestedLanguage)
                ->orWhere(function ($q) use ($requestedLanguage) {
                    $q->whereNull('contents.i18n_parent_id')
                        ->whereNotIn('contents.id', function ($sub) use ($requestedLanguage) {
                            $sub->select('i18n_parent_id')
                                ->from('contents')
                                ->where('language_iso', $requestedLanguage)
                                ->whereNotNull('i18n_parent_id')
                                ->whereNull('deleted_at');
                        });
                });
        });
    }
}
