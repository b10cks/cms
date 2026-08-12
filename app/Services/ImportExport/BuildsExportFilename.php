<?php

namespace App\Services\ImportExport;

use App\Models\Management\Space;

/**
 * Shared naming scheme for exported download files:
 * "{space id}_{suffix}_{Y-m-d}.{extension}".
 */
trait BuildsExportFilename
{
    protected function buildExportFilename(Space $space, string $suffix, string $extension): string
    {
        $date = now()->format('Y-m-d');

        return "{$space->id}_{$suffix}_{$date}.{$extension}";
    }
}
