<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Space\Content;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\Support\AiSseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentInteractionStreamController extends Controller
{
    use SpaceFromQuery;

    public function __construct(
        protected AiStreamService $streamService,
        protected ModelRegistry $registry
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'prompt' => 'required|string',
            'content' => 'sometimes|nullable|array',
            'content_id' => 'sometimes|nullable|string',
            'contentId' => 'sometimes|nullable|string',
            'files' => 'sometimes|array',
            'config_id' => 'sometimes|nullable|string',
            'mentions' => 'sometimes|array',
            'mentions.*.type' => 'sometimes|string|in:content,block',
            'mentions.*.id' => 'sometimes|string',
            'mentions.*.label' => 'sometimes|string',
        ]);

        $space = $this->getSpaceFromQuery();
        $this->authorizeSpaceAbility($space, 'content.manage');
        app()->offsetSet('currentSpace', $space);

        $contentId = $request->input('content_id') ?? $request->input('contentId');
        $content = $contentId ? Content::find($contentId) : null;

        $aiConfig = null;

        if ($configId = $request->input('config_id')) {
            $aiConfig = $space->aiConfigs()->find($configId);

            if (! $aiConfig) {
                $aiConfig = $space->defaultAiConfig;
            }
        }

        if (! $aiConfig) {
            $aiConfig = $space->defaultAiConfig;
        }

        $prompt = $request->string('prompt');
        $context = [
            'content' => $content?->current_version?->content ?? $request->input('content', []),
            'root_block' => $content?->block ? [
                'slug' => $content->block->slug,
                'name' => $content->block->name,
                'schema' => $content->block->schema->toArray(),
            ] : null,
            'mentions' => $request->input('mentions', []),
        ];
        $files = $request->input('files', []);

        return AiSseStream::response(
            fn () => $this->streamService->stream($space, $prompt, $context, $files, $aiConfig),
            ['endpoint' => 'content-interaction', 'space' => $space->id],
        );
    }
}
