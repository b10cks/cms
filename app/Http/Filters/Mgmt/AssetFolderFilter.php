<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

/**
 * Filters asset folders in the management API.
 *
 * Supported filter syntax:
 * - Plain string filters such as `name`, `external_id`, and `storage_id`
 * - Null-aware parent filtering via `parent_id=null`
 * - Text search across name and description via `search`
 * - Range filters for timestamps via `created_at` and `updated_at`
 *
 * @sortDescription Sort by `name`, `external_id`, `created_at`, or `updated_at`. Prefix with `-` for descending order.
 */
class AssetFolderFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'external_id', 'created_at', 'updated_at'];

    /**
     * @filterDescription Filter folders by name using a partial match.
     * @filterType string
     * @filterExample media
     */
    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    /**
     * @filterDescription Filter folders by exact external identifier.
     * @filterType string
     * @filterExample folder_ext_123
     */
    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    /**
     * @filterDescription Filter folders by exact storage identifier.
     * @filterType string
     * @filterExample 01HZX3ABCD1234567890EFGHJK
     */
    public function storage_id($value)
    {
        $this->builder->where('storage_id', $value);
    }

    /**
     * @filterDescription Filter by parent folder id. Use `null` to return only root folders.
     * @filterType string
     * @filterExample null
     */
    public function parent_id($value)
    {
        if ($value === null || $value === 'null') {
            $this->builder->whereNull('parent_id');
        } else {
            $this->builder->where('parent_id', $value);
        }
    }

    /**
     * @filterDescription Full-text style search across folder name and description using partial matches.
     * @filterType string
     * @filterExample campaign
     */
    public function search($value)
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('name', 'LIKE', "%{$value}%")
                ->orWhere('description', 'LIKE', "%{$value}%");
        });
    }

    /**
     * @filterDescription Filter by creation timestamp. Supports a single value or a range using `value1...value2`.
     * @filterType string
     * @filterFormat date-time
     * @filterExample 2024-01-01T00:00:00Z...2024-12-31T23:59:59Z
     */
    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    /**
     * @filterDescription Filter by last update timestamp. Supports a single value or a range using `value1...value2`.
     * @filterType string
     * @filterFormat date-time
     * @filterExample 2024-01-01T00:00:00Z...2024-12-31T23:59:59Z
     */
    public function updated_at($value)
    {
        $this->applyRangeFilter('updated_at', $value);
    }
}
