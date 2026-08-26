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

        // A space that never finished provisioning has no database to read the
        // snapshot from, so say that instead of failing on the connection.
        abort_if(
            in_array($sourceSpace->state, ['draft', 'error'], true),
            422,
            __('validation.blueprint.source_space_not_ready'),
        );

        return $sourceSpace;
    }
}
