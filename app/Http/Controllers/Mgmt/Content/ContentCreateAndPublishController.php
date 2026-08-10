<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\CreateContent;
use App\Actions\Content\PublishContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\PublishContentRequest;
use App\Http\Resources\Management\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Audit\AuditActor;
use App\Services\Search\SearchService;
use Illuminate\Support\Arr;

/**
 * Create an entry and publish it in one round trip.
 *
 * A new language version only exists client side until it is saved, so the
 * publish route — which binds an existing {content} — cannot reach it. Without
 * this endpoint a translation has to be saved before it can go live, which is
 * an extra step for the editor and leaves an unpublished row behind whenever
 * the publish then fails.
 *
 * Create and publish share one transaction: publish validation is stricter than
 * save validation, so a failure here must not leave the just-created draft
 * behind.
 */
class ContentCreateAndPublishController extends Controller
{
    public function __invoke(
        PublishContentRequest $request,
        Space $space,
        CreateContent $createAction,
        PublishContent $publishAction,
        SearchService $searchService,
    ): ContentResource
    {
        $this->authorize('create', [Content::class, $space]);

        $data = $request->validated();
        $content = new Content();

        $content->getConnection()->transaction(function () use (
            $data,
            $content,
            $space,
            $request,
            $createAction,
            $publishAction,
        ): void {
            $createAction->execute(Arr::except($data, ['message', 'published_at']), $content, $space, $request->user());

            $this->authorize('publish', [$content, $space]);

            // The publish payload deliberately carries no `content` key: the
            // create action already canonicalised the submission (schema
            // defaults, assigned serials, composed slug) into the first
            // version, so publishing has to take that version as-is rather
            // than the raw request body.
            $content->load('current_version')->withoutAudit();
            $publishAction->executeWithoutIndex(
                Arr::only($data, ['message', 'published_at']),
                $content,
                $space,
                $request->user(),
            );
        });

        $searchService->indexContent($content, $space);
        $content->auditSpaceEvent('published', AuditActor::user($request->user()));

        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);

        return new ContentResource($content);
    }
}
