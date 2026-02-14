<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Actions\Content\InteractWithContent;
use App\Http\Controllers\Controller;
use App\Models\Space\Content;
use App\Models\Traits\SpaceFromQuery;
use Illuminate\Http\Request;

class ContentInteractionController extends Controller
{
    use SpaceFromQuery;

    public function __invoke(Request $request, InteractWithContent $action)
    {
        $request->validate([
            'prompt' => 'required|string',
            'content' => 'sometimes|nullable|array',
            'contentId' => 'required|string',
            'files' => 'sometimes|array',
        ]);

        $space = $this->getSpaceFromQuery();
        app()->offsetSet('currentSpace', $space);

        $content = Content::findOrFail($request->input('contentId'));

        $result = $action->execute(
            prompt: $request->string('prompt'),
            content: $content,
            space: $space,
            files: $request->input('files', [])
        );

        \Log::info('Content Interaction Result', ['result' => $result]);

        return [
            'data' => $result,
        ];
    }
}
