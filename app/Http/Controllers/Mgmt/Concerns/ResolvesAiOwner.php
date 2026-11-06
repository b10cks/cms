<?php

namespace App\Http\Controllers\Mgmt\Concerns;

use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Ai\Knowledge\AiLayers;
use Illuminate\Http\Request;

/**
 * AI settings controllers serve both `spaces/{space}/ai/…` and
 * `teams/{team}/ai/…`; the owner is whichever the route carries.
 *
 * Laravel passes route parameters by position, so actions with an ID take
 * the owner parameter (`$owner`) before it; aiOwner() resolves it by name.
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

    /** A glossary this owner may read: its own, or one inherited from its teams. Writes need the owner itself. */
    protected function readableGlossary(Request $request, string $id): AiGlossary
    {
        $owner = $this->aiOwner($request);
        $this->authorize('viewAny', [AiGlossary::class, $owner]);

        return AiGlossary::query()
            ->inLayers($owner instanceof Space ? $owner->id : null, app(AiLayers::class)->teamIds($owner))
            ->findOrFail($id);
    }
}
