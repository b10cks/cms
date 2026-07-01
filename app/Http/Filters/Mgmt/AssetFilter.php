<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;
use CodersCantina\Filter\ExtendedFilter;

class AssetFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['filename', 'size', 'extension', 'mime_type', 'external_id', 'created_at', 'updated_at', 'license_expires_at', 'rights_status'];

    public function filename($value)
    {
        $this->builder->where('filename', 'LIKE', "%{$value}%");
    }

    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    public function size($value)
    {
        $this->applyDynamicFilter('size', $value);
    }

    public function folder($value)
    {
        if ($value === null || $value === 'null') {
            $this->builder->whereNull('folder_id');
        } else {
            $this->builder->where('folder_id', $value);
        }
    }

    public function extension($value)
    {
        if (is_array($value)) {
            $this->builder->whereIn('extension', $value);
        } else {
            $this->builder->where('extension', 'LIKE', "%{$value}%");
        }
    }

    public function mime_type($value)
    {
        $value = $this->ensureArray($value);
        $this->builder->whereIn('mime_type', $value);
    }

    public function tags($value)
    {
        if (is_array($value)) {
            foreach ($value as $tag) {
                $this->builder->whereJsonContains('tags', $tag);
            }
        } else {
            $this->builder->whereJsonContains('tags', $value);
        }
    }

    public function q($value)
    {
        $this->builder->where(function($query) use ($value) {
            $query->where('filename', 'LIKE', "%{$value}%")
                ->orWhere('extension', 'LIKE', "%{$value}%");
        });
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyRangeFilter('updated_at', $value);
    }

    public function rights_status($value)
    {
        $this->applyDynamicFilter('rights_status', $value);
    }

    public function expiring_before($value)
    {
        $this->builder
            ->whereNotNull('license_expires_at')
            ->where('license_expires_at', '<=', $value);
    }
}
