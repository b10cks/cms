<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\ContentTreeAiService;
use App\Services\Ai\Support\AiSseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentTreeInteractionStreamController extends Controller
{
    use SpaceFromQuery;

    public function __construct(
        protected ContentTreeAiService $treeAiService
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'prompt' => 'required|string',
            'tree' => 'required|array',
            'config_id' => 'sometimes|nullable|string',
            'mentions' => 'sometimes|array',
            'mentions.*.type' => 'sometimes|string|in:content,block,draft-content',
            'mentions.*.id' => 'sometimes|string',
            'mentions.*.label' => 'sometimes|string',
        ]);

        $space = $this->getSpaceFromQuery();
        $this->authorizeSpaceAbility($space, 'content.manage');
        app()->offsetSet('currentSpace', $space);

        $aiConfig = $this->resolveAiConfig($space, $request->input('config_id'));

        $prompt = $request->string('prompt');
        $tree = $request->input('tree', []);
        $mentions = $request->input('mentions', []);

        return AiSseStream::response(
            fn () => $this->treeAiService->stream($space, $prompt, $tree, $mentions, $aiConfig),
            ['endpoint' => 'content-tree-interaction', 'space' => $space->id],
        );
    }
}
