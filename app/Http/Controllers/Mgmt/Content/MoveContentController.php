<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\MoveContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Http\Request;

class MoveContentController extends Controller
{
    public function __invoke(Request $request, Space $space, Content $content, MoveContent $action): ContentResource
    {
        $this->authorize('update', [$content, $space]);

        $validated = $request->validate([
            'parent_id' => 'nullable|string|exists:contents,id',
            'position' => 'nullable|integer|min:0',
        ]);

        $action->execute(
            $content,
            $validated['parent_id'] ?? null,
            $validated['position'] ?? null,
            $space
        );

        $content->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);

        return new ContentResource($content);
    }
}
