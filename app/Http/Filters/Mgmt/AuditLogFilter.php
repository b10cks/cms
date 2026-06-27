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
        if (! is_string($value)) {
            return;
        }

        // Datetime range from the date-range picker: "<start>...<end>".
        // Either side may be empty for an open-ended range.
        if (str_contains($value, '...')) {
            [$start, $end] = array_pad(explode('...', $value, 2), 2, '');

            if ($start !== '') {
                $this->builder->where('created_at', '>=', $this->formatDate($start, true));
            }

            if ($end !== '') {
                $this->builder->where('created_at', '<=', $this->formatDate($end, false));
            }

            return;
        }

        // Operator-prefixed form (e.g. "gte:2026-06-26"), with date normalization.
        $this->applyAdvancedDateFilter('created_at', $value);
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
