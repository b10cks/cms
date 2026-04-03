<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class AuditLogFilter extends AdvancedFilter
{
    protected array $sortableColumns = [
        'created_at',
        'owner_name',
        'operation',
        'referenced_type',
        'name',
    ];

    public function created_at($value): void
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function owner_type($value): void
    {
        $this->applyDynamicFilter('owner_type', $value);
    }

    public function owner($value): void
    {
        $this->applyDynamicFilter('owner_name', $value);
    }

    public function operation($value): void
    {
        $this->applyDynamicFilter('operation', $value);
    }

    public function referenced_type($value): void
    {
        $this->applyDynamicFilter('referenced_type', $value);
    }

    public function name($value): void
    {
        $this->applyDynamicFilter('name', $value);
    }
}
