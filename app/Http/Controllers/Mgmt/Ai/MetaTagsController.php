<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use Illuminate\Http\Request;

class MetaTagsController extends Controller
{
    use SpaceFromQuery;

    public function __invoke(Request $request, AiStreamService $service)
    {
        $space = $this->getSpaceFromQuery();

        $request->validate([
            'config_id' => 'sometimes|nullable|string',
            'context' => 'sometimes|nullable|array',
            'language' => 'sometimes|nullable|string|max:20',
        ]);

        $aiConfig = $space->defaultAiConfig;

        if ($configId = $request->input('config_id')) {
            $aiConfig = $space->aiConfigs()->find($configId) ?? $aiConfig;
        }

        $promptBuilder = new SystemPromptBuilder($aiConfig);

        $context = $request->json('context');
        $language = strtolower(trim((string) $request->input('language', '')));

        if ($language === '') {
            $language = data_get($space->settings, 'default_language.iso', 'en');
        }

        $userPrompt = "Target language: {$language}\n"
            ."Important: All generated meta tag fields must be written strictly in {$language}. "
            ."Do not return English unless {$language} is English.\n\n"
            ."Page content to analyze:\n"
            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = $service->generate($space, $promptBuilder->forMetaTags(), $userPrompt, [], $aiConfig);

        if ($result === null) {
            return ['data' => []];
        }

        return [
            'data' => json_decode($result, false) ?? [],
        ];
    }
}
