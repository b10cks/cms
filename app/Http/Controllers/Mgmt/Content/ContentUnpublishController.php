<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\UnpublishContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Audit\AuditActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContentUnpublishController extends Controller
{
    public function __invoke(Request $request, Space $space, Content $content, UnpublishContent $action): ContentResource
    {
        $this->authorize('publish', [$content, $space]);

        try {
            $content->withoutAudit();
            $action->execute($content, $space);
        } catch (\Exception $e) {
            Log::error('Failed to unpublish content', ['content_id' => $content->id, 'space_id' => $space->id, 'error' => $e->getMessage()]);
            abort(500, 'Failed to unpublish content');
        }

        $content->auditSpaceEvent('unpublished', AuditActor::user($request->user()));
        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings']);

        return new ContentResource($content);
    }
}
