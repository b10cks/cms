<?php

namespace App\Http\Filters\Mgmt;

use App\Models\Management\Space;
use CodersCantina\Filter\ExtendedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ContentMenuFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'slug', 'language_iso', 'position', 'published_at', 'created_at', 'updated_at'];

    protected Space $space;

    public function setSpace(Space $space): void
    {
        $this->space = $space;
    }

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function slug($value)
    {
        $this->builder->where('slug', 'LIKE', "%{$value}%");
    }

    public function block_id($value)
    {
        $this->builder->where('block_id', $value);
    }

    public function parent_id($value)
    {
        if ($value === 'null') {
            $this->builder->whereNull('parent_id');
        } else {
            $this->builder->where('parent_id', $value);
        }
    }

    public function apply(Relation|Builder|\Illuminate\Database\Query\Builder $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        $builder = parent::apply($builder);

        $filters = $this->getFilters();

        // One row per family: its default-language row, or the entry itself
        // when the family has none (an entry made in another language).
        if (! array_key_exists('language_iso', $filters)) {
            $defaultLanguage = $this->space->settings->getDefaultLanguage();

            $builder->where(fn ($query) => $query
                ->where('language_iso', $defaultLanguage)
                ->orWhere(fn ($query) => $query
                    ->whereNull('i18n_parent_id')
                    ->whereNotExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('contents as default_translations')
                        ->whereColumn('default_translations.i18n_parent_id', 'contents.id')
                        ->where('default_translations.language_iso', $defaultLanguage)
                        ->whereNull('default_translations.deleted_at'))));
        }

        return $builder;
    }
}
