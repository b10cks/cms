<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Ai\Changesets\ContentFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What AI left on entries for whoever publishes them: open conflicts, and flagged
 * wording that is still in the draft. It informs and never blocks a publish.
 */
class AiContentFlagsController extends Controller
{
    /** How many details one request names. A publish dialog shows far fewer. */
    private const DETAIL_LIMIT = 50;

    /**
     * Figures per entry, for the tree and for publish counts. Entries with nothing open are left out.
     *
     * @apiQuery release_id string Only the entries of this release.
     *
     * @apiResponse 200 @AiContentFlagCounts
     */
    public function index(Request $request, Space $space, ContentFlags $flags): JsonResponse
    {
        $this->authorize('viewAny', [Content::class, $space]);
        $validated = $request->validate(['release_id' => ['sometimes', 'string', 'max:40']]);

        $ids = isset($validated['release_id'])
            ? ContentVersion::query()->where('release_id', $validated['release_id'])->distinct()->pluck('content_id')->all()
            : null;

        return response()->json(['data' => (object) $flags->counts($ids)]);
    }

    /**
     * The conflicts and flagged wording of some entries, named by field.
     *
     * @apiQuery ids[] string required Entries, at most 50.
     *
     * @apiResponse 200 data:@AiContentFlagEntry[]
     */
    public function details(Request $request, Space $space, ContentFlags $flags): JsonResponse
    {
        $this->authorize('viewAny', [Content::class, $space]);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::DETAIL_LIMIT],
            'ids.*' => ['string', 'max:40'],
        ]);

        return response()->json(['data' => $flags->details(array_values($validated['ids']))]);
    }
}
