<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Prompts\SystemPromptBuilder;
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
            . "Return only the translated flat JSON object.\n\n"
            . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $systemPrompt = $promptBuilder->forTranslation();

        return new StreamedResponse(
            function () use ($space, $systemPrompt, $userPrompt) {
                if (ob_get_level() == 0) {
                    ob_start();
                }

                $lastActivity = time();
                $pingInterval = 15;

                echo ": ping\n\n";
                ob_flush();
                flush();

                try {
                    foreach ($this->streamService->streamWithSystemPrompt(
                        $space,
                        $systemPrompt,
                        $userPrompt,
                    ) as $event) {
                        $now = time();

                        if (($now - $lastActivity) >= $pingInterval) {
                            echo ": ping\n\n";
                            ob_flush();
                            flush();
                            $lastActivity = $now;
                        }

                        echo $event->toJsonLine() . "\n\n";
                        ob_flush();
                        flush();

                        $lastActivity = $now;

                        if ($event->type->value === 'done' || $event->type->value === 'error') {
                            ob_flush();
                            flush();
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    echo
                        'data: '
                            . json_encode([
                                'type' => 'error',
                                'message' => $e->getMessage(),
                            ])
                            . "\n\n"
                    ;
                    ob_flush();
                    flush();
                }

                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'close',
            ],
        );
    }
}
