<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class AssetTagFilter extends ExtendedFilter
{
    public function q($value)
    {
        $this->builder->where('name', 'like', "%$value%");
    }

    public function icon($icon)
    {
        $this->builder->where('icon', $icon);
    }

    public function color($color)
    {
        $this->builder->where('color', $color);
    }
}
