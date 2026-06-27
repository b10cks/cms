<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use App\Services\Ai\Prompts\UserPromptBuilder;
use App\Services\Ai\Support\AiSseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranslationStreamController extends Controller
{
    use SpaceFromQuery;

    public function __construct(
        protected AiStreamService $streamService,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'source' => 'required|string',
            'target' => 'required|string',
            'fields' => 'required|array',
            'config_id' => 'sometimes|nullable|string',
        ]);

        $space = $this->getSpaceFromQuery();
        $this->authorizeSpaceAbility($space, 'content.manage');
        app()->offsetSet('currentSpace', $space);

        $aiConfig = $this->resolveAiConfig($space, $request->input('config_id'));

        $userPrompt = UserPromptBuilder::translation(
            (string) $request->string('source'),
            (string) $request->string('target'),
            $request->input('fields'),
        );

        $systemPrompt = (new SystemPromptBuilder($aiConfig))->forTranslation();

        return AiSseStream::response(
            fn () => $this->streamService->streamWithSystemPrompt(
                $space,
                $systemPrompt,
                $userPrompt,
                aiConfig: $aiConfig,
            ),
            ['endpoint' => 'translate', 'space' => $space->id],
        );
    }
}
