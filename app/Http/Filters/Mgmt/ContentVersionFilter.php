<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class ContentVersionFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['created_at'];

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function external_id($value)
    {
        $this->applyDynamicFilter('external_id', $value);
    }

    public function created_by($value)
    {
        $this->builder->where('created_by_id', $value);
    }

    public function isPublished($value)
    {
        if ($value === 'true' || $value === '1') {
            $this->builder->whereHas('content', function ($query) {
                $query->whereColumn('content_versions.id', 'contents.published_version_id');
            });
        } elseif ($value === 'false' || $value === '0') {
            $this->builder->whereHas('content', function ($query) {
                $query->whereColumn('content_versions.id', '!=', 'contents.published_version_id')
                    ->orWhereNull('contents.published_version_id');
            });
        }
    }

    public function isCurrentVersion($value)
    {
        if ($value === 'true' || $value === '1') {
            $this->builder->whereHas('content', function ($query) {
                $query->whereColumn('content_versions.id', 'contents.current_version_id');
            });
        } elseif ($value === 'false' || $value === '0') {
            $this->builder->whereHas('content', function ($query) {
                $query->whereColumn('content_versions.id', '!=', 'contents.current_version_id');
            });
        }
    }
}
