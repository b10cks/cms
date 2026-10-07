<?php

namespace App\Http\Controllers\Mgmt\Release;

use App\Http\Controllers\Controller;
use App\Http\Requests\Release\AssignContentVersionRequest;
use App\Http\Resources\Management\ReleaseDetailResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use Illuminate\Support\Facades\Log;

class ReleaseVersionAssignController extends Controller
{
    public function __invoke(Space $space, Release $release, AssignContentVersionRequest $request): ReleaseDetailResource
    {
        $this->authorize('assignVersions', [$release, $space]);
        abort_if(!!$release->committed_at, 400, 'Release is already committed');

        try {
            $validated = $request->validated();
            $versionIds = [
                ...($validated['version_ids'] ?? []),
                ...$this->draftVersionIds($validated['content_ids'] ?? []),
            ];

            ContentVersion::query()
                ->whereIn('id', $versionIds)
                ->update(['release_id' => $release->id]);

            $release->load(['versions' => fn ($query) => $query->listSummary()])->loadCount(['versions']);

            return (new ReleaseDetailResource($release))->additional(['meta' => ['assigned' => \count($versionIds)]]);
        } catch (\Exception $e) {
            Log::error('Failed to assign content versions to release', [
                'release_id' => $release->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to assign content versions to release');
        }
    }

    /**
     * A release carries unpublished drafts, so an entry contributes its current
     * version only while that is not live. A version held by a committed
     * release stays where it is.
     *
     * @param  list<string>  $contentIds
     * @return list<string>
     */
    private function draftVersionIds(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        return ContentVersion::query()
            ->whereIn('id', Content::query()->whereIn('id', $contentIds)->select('current_version_id'))
            ->whereNull('published_at')
            ->where(fn ($query) => $query
                ->whereNull('release_id')
                ->orWhereIn('release_id', Release::query()->whereNull('committed_at')->select('id')))
            ->pluck('id')
            ->all();
    }
}
