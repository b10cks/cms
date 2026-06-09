<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class DataEntryFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['key', 'value', 'external_id', 'created_at', 'updated_at'];

    /**
     * Intercepts `dimension_*` query params (PHP converts `dimension.en` → `dimension_en`
     * in query strings) and applies JSON_EXTRACT-based filtering on the `dimensions` column.
     * Supports operators: empty, !empty, null, !null
     */
    public function apply(Builder|\Illuminate\Database\Query\Builder|Relation $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        $this->builder = $builder;

        foreach ($this->getFilters() as $name => $value) {
            if (str_starts_with($name, 'dimension_')) {
                $key = substr($name, strlen('dimension_'));
                $this->applyDimensionFilter($key, (string) $value);
            }
        }

        return parent::apply($builder);
    }

    private function applyDimensionFilter(string $key, string $value): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            return;
        }

        $jsonPath = '$.' . $key;
        $operator = explode(':', $value, 2)[0];

        // JSON_EXTRACT returns SQL NULL for a missing key, but JSON null (`{"en":null}`)
        // is a distinct JSON type — not SQL NULL. JSON_TYPE(...) = 'NULL' catches that case.
        match ($operator) {
            'empty' => $this->builder->where(function ($q) use ($jsonPath) {
                $q->whereRaw('JSON_EXTRACT(`dimensions`, ?) IS NULL', [$jsonPath])
                    ->orWhereRaw("JSON_TYPE(JSON_EXTRACT(`dimensions`, ?)) = 'NULL'", [$jsonPath])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(`dimensions`, ?)) = ''", [$jsonPath]);
            }),
            '!empty' => $this->builder->where(function ($q) use ($jsonPath) {
                $q->whereRaw('JSON_EXTRACT(`dimensions`, ?) IS NOT NULL', [$jsonPath])
                    ->whereRaw("JSON_TYPE(JSON_EXTRACT(`dimensions`, ?)) != 'NULL'", [$jsonPath])
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`dimensions`, ?)) != ''", [$jsonPath]);
            }),
            'null' => $this->builder->where(function ($q) use ($jsonPath) {
                $q->whereRaw('JSON_EXTRACT(`dimensions`, ?) IS NULL', [$jsonPath])
                    ->orWhereRaw("JSON_TYPE(JSON_EXTRACT(`dimensions`, ?)) = 'NULL'", [$jsonPath]);
            }),
            '!null' => $this->builder->where(function ($q) use ($jsonPath) {
                $q->whereRaw('JSON_EXTRACT(`dimensions`, ?) IS NOT NULL', [$jsonPath])
                    ->whereRaw("JSON_TYPE(JSON_EXTRACT(`dimensions`, ?)) != 'NULL'", [$jsonPath]);
            }),
            default => null,
        };
    }

    public function q($value)
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('key', 'LIKE', "%{$value}%")
                ->orWhere('value', 'LIKE', "%{$value}%");
        });
    }

    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    public function key($value)
    {
        $this->builder->where('key', 'LIKE', "%{$value}%");
    }

    public function value($value)
    {
        $this->builder->where('value', 'LIKE', "%{$value}%");
    }

    public function active($value)
    {
        $this->builder->where('is_active', $value === 'true');
    }
}
