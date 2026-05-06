<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

/**
 * Management API filters for listing content entries.
 *
 * Supported query patterns:
 * - Text filters like `name` and `slug` perform partial `LIKE` matches.
 * - Exact filters like `external_id`, `block_id`, and `language_iso` match a single value.
 * - Nullable relation filters like `parent_id` and `i18n_parent_id` accept the string `null`
 *   to find root-level or untranslated records.
 * - Boolean-like filters such as `published` accept `true` / `1` and `false` / `0`.
 * - Range filters like `published_at`, `created_at`, and `updated_at` support the common
 *   range syntax handled by the shared filter base implementation.
 *
 * @filterDescription Filter management content entries.
 * @sortDescription Sort results by field. Prefix with `-` for descending order.
 */
class ContentFilter extends ExtendedFilter
{
    protected array $sortableColumns = [
        'name',
        'slug',
        'language_iso',
        'external_id',
        'published_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Filter by content name using a partial match.
     *
     * @filterDescription Filter by content name using a partial match.
     * @filterExample homepage
     */
    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    /**
     * Filter by content slug using a partial match.
     *
     * @filterDescription Filter by content slug using a partial match.
     * @filterExample home
     */
    public function slug($value)
    {
        $this->builder->where('slug', 'LIKE', "%{$value}%");
    }

    /**
     * Filter by external identifier.
     *
     * @filterDescription Filter by external identifier.
     * @filterExample ext-home-001
     */
    public function external_id($value)
    {
        $this->builder->where('contents.external_id', $value);
    }

    /**
     * Filter by block identifier.
     *
     * @filterDescription Filter by block identifier.
     * @filterExample 01HZX2K9J7YQ8V3M4N5P6R7S8T
     */
    public function block_id($value)
    {
        $this->builder->where('block_id', $value);
    }

    /**
     * Filter by parent content identifier.
     *
     * Use the literal string `null` to return root-level entries.
     *
     * @filterDescription Filter by parent content identifier. Use `null` to return only root-level entries.
     * @filterExample null
     */
    public function parent_id($value)
    {
        if ($value === 'null') {
            $this->builder->whereNull('parent_id');
        } else {
            $this->builder->where('parent_id', $value);
        }
    }

    /**
     * Filter by content language ISO code.
     *
     * @filterDescription Filter by content language ISO code.
     * @filterExample en
     */
    public function language_iso($value)
    {
        $this->builder->where('language_iso', $value);
    }

    /**
     * Filter by i18n parent content identifier.
     *
     * Use the literal string `null` to return canonical entries without an i18n parent.
     *
     * @filterDescription Filter by i18n parent content identifier. Use `null` to return canonical entries without an i18n parent.
     * @filterExample null
     */
    public function i18n_parent_id($value)
    {
        if ($value === 'null') {
            $this->builder->whereNull('i18n_parent_id');
        } else {
            $this->builder->where('i18n_parent_id', $value);
        }
    }

    /**
     * Filter by publication state.
     *
     * Accepted values:
     * - `true` or `1`: only published entries
     * - `false` or `0`: only unpublished entries
     *
     * @filterDescription Filter by publication state. Accepted values: `true` / `1` for published, `false` / `0` for unpublished.
     * @filterType boolean
     * @filterExample true
     */
    public function published($value)
    {
        if ($value === 'true' || $value === '1') {
            $this->builder->whereNotNull('published_at');
        } elseif ($value === 'false' || $value === '0') {
            $this->builder->whereNull('published_at');
        }
    }

    /**
     * Filter by publication timestamp.
     *
     * Supports the shared range syntax, for example:
     * - exact value
     * - `from...to`
     *
     * @filterDescription Filter by publication timestamp. Supports range syntax like `2024-01-01...2024-12-31`.
     * @filterFormat date-time
     * @filterExample 2024-01-01...2024-12-31
     */
    public function published_at($value)
    {
        $this->applyRangeFilter('published_at', $value);
    }

    /**
     * Filter by creation timestamp.
     *
     * Supports the shared range syntax, for example:
     * - exact value
     * - `from...to`
     *
     * @filterDescription Filter by creation timestamp. Supports range syntax like `2024-01-01...2024-12-31`.
     * @filterFormat date-time
     * @filterExample 2024-01-01...2024-12-31
     */
    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    /**
     * Filter by last update timestamp.
     *
     * Supports the shared range syntax, for example:
     * - exact value
     * - `from...to`
     *
     * @filterDescription Filter by last update timestamp. Supports range syntax like `2024-01-01...2024-12-31`.
     * @filterFormat date-time
     * @filterExample 2024-01-01...2024-12-31
     */
    public function updated_at($value)
    {
        $this->applyRangeFilter('updated_at', $value);
    }
}
