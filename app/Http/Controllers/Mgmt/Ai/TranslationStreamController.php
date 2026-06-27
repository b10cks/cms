<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Prompts\SystemPromptBuilder;
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

        $aiConfig = $space->defaultAiConfig;

        if ($configId = $request->input('config_id')) {
            $aiConfig = $space->aiConfigs()->find($configId) ?? $aiConfig;
        }

        $promptBuilder = new SystemPromptBuilder($aiConfig);

        $source = $request->string('source');
        $target = $request->string('target');
        $fields = $request->input('fields');

        $userPrompt =
            "Translate the following texts from {$source} to {$target}.\n"
            ."Return only the translated flat JSON object.\n\n"
            .json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $systemPrompt = $promptBuilder->forTranslation();

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
