<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Filters for the mass-edit content grid. Supports the `operator:value` syntax
 * emitted by the SearchFilter component, plus dynamic `field_*` filters matching
 * against the current draft version's JSON content (top-level field keys).
 */
class ContentMassEditFilter extends AdvancedFilter
{
    protected array $sortableColumns = [
        'name',
        'slug',
        'full_slug',
        'position',
        'published_at',
        'created_at',
        'updated_at',
    ];

    public function apply(Builder|\Illuminate\Database\Query\Builder|Relation $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        $this->builder = $builder;

        foreach ($this->getFilters() as $name => $value) {
            if (\is_string($value) && str_starts_with($name, 'field_')) {
                $this->applyFieldValueFilter(substr($name, \strlen('field_')), $value);
            }
        }

        return parent::apply($builder);
    }

    public function name($value)
    {
        $this->applyDynamicFilter('name', $value);
    }

    public function slug($value)
    {
        $this->applyDynamicFilter('slug', $value);
    }

    public function full_slug($value)
    {
        $this->applyDynamicFilter('full_slug', $value);
    }

    public function external_id($value)
    {
        $this->applyDynamicFilter('contents.external_id', $value);
    }

    public function block_id($value)
    {
        if (! \is_string($value)) {
            return;
        }

        [, $blockId] = $this->parseOperatorAndValue($value);

        $this->builder->where('block_id', $blockId);
    }

    public function published($value)
    {
        if (! \is_string($value)) {
            return;
        }

        [, $published] = $this->parseOperatorAndValue($value);

        if (filter_var($published, FILTER_VALIDATE_BOOLEAN)) {
            $this->builder->whereNotNull('published_at');
        } else {
            $this->builder->whereNull('published_at');
        }
    }

    public function published_at($value)
    {
        $this->applyAdvancedDateFilter('contents.published_at', $value);
    }

    public function created_at($value)
    {
        $this->applyAdvancedDateFilter('contents.created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyAdvancedDateFilter('contents.updated_at', $value);
    }

    /**
     * Match a top-level content field of the current version, e.g.
     * `field_title=like:Home`. Values live in the version's JSON `content`
     * column; objects (meta, tables) are matched against their serialized form.
     */
    private function applyFieldValueFilter(string $key, string $value): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            return;
        }

        [$operator, $filterValue] = $this->parseOperatorAndValue($value);
        // Laravel's JSON path syntax compiles to the right extract function per grammar.
        $column = 'content->'.$key;
        $likeValue = addcslashes($filterValue, '\\%_');

        $this->builder->whereHas('current_version', function ($query) use ($column, $operator, $filterValue, $likeValue) {
            match ($operator) {
                'like' => $query->where($column, 'LIKE', "%{$likeValue}%"),
                '!like' => $query->where($column, 'NOT LIKE', "%{$likeValue}%"),
                '^like' => $query->where($column, 'LIKE', "{$likeValue}%"),
                'like$' => $query->where($column, 'LIKE', "%{$likeValue}"),
                'neq' => $query->where($column, '!=', $filterValue),
                'empty' => $query->where(function ($q) use ($column) {
                    $q->whereNull($column)->orWhere($column, '');
                }),
                '!empty' => $query->whereNotNull($column)->where($column, '!=', ''),
                default => $query->where($column, $filterValue),
            };
        });
    }
}
