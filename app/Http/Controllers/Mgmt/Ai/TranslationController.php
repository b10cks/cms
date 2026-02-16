<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use Illuminate\Http\Request;

class TranslationController extends Controller
{
    use SpaceFromQuery;

    public function __invoke(Request $request, AiStreamService $service)
    {
        $space = $this->getSpaceFromQuery();
        $aiConfig = $space->defaultAiConfig ?? $space->aiConfig;
        $promptBuilder = new SystemPromptBuilder($aiConfig);

        $source = $request->string('source');
        $target = $request->string('target');
        $fields = $request->json('fields');

        $userPrompt = "Translate the following texts from {$source} to {$target}.\n\n"
            . json_encode($fields);

        $result = $service->generate(
            $space,
            $promptBuilder->forTranslation(),
            $userPrompt
        );

        if ($result === null) {
            return ['data' => []];
        }

        return [
            'data' => json_decode($result, false) ?? [],
        ];
    }
}
