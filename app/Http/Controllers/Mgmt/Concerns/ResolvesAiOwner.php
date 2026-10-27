<?php

namespace App\Http\Controllers\Mgmt\Concerns;

use App\Models\Management\Space;
use App\Models\Management\Team;
use Illuminate\Http\Request;

/**
 * AI settings controllers serve both `spaces/{space}/ai/…` and
 * `teams/{team}/ai/…`; the owner is whichever the route carries.
 */
trait ResolvesAiOwner
{
    protected function aiOwner(Request $request): Space|Team
    {
        $space = $request->route('space');
        if ($space !== null) {
            return $space instanceof Space ? $space : Space::query()->findOrFail($space);
        }

        $team = $request->route('team');
        abort_if($team === null, 404);

        return $team instanceof Team ? $team : Team::query()->findOrFail($team);
    }
}
