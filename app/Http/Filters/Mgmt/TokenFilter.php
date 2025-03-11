<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;
use Illuminate\Http\Request;

class TokenFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'created_at', 'expires_at', 'last_used_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function abilities($value)
    {
        if (is_array($value)) {
            foreach ($value as $ability) {
                $this->builder->whereJsonContains('abilities', $ability);
            }
        } else {
            $this->builder->whereJsonContains('abilities', $value);
        }
    }

    public function expires_at($value)
    {
        $this->applyRangeFilter('expires_at', $value);
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    /**
     * Create a filter from a request.
     */
    public static function fromRequest(Request $request): self
    {
        return new self($request->all());
    }
}
