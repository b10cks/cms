<?php

namespace App\Http\Controllers\Mgmt\Concerns;

use App\Models\Management\Space;
use Illuminate\Http\Request;

trait ResolvesBlueprintSourceSpace
{
    /**
     * Resolve the space a blueprint is snapshotted from. Being allowed to read
     * the source space is the only requirement — it may live in a different
     * team than the blueprint being created.
     */
    protected function resolveSourceSpace(Request $request): ?Space
    {
        if (! $request->filled('source_space_id')) {
            return null;
        }

        $sourceSpace = Space::findOrFail($request->input('source_space_id'));
        $this->authorize('view', $sourceSpace);

        return $sourceSpace;
    }
}
