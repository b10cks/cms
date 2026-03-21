<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\ContentTreeAiService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentTreeInteractionStreamController extends Controller
{
    use SpaceFromQuery;

    public function __construct(
        protected ContentTreeAiService $treeAiService
    ) {
    }

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
        app()->offsetSet('currentSpace', $space);

        $aiConfig = null;

        if ($configId = $request->input('config_id')) {
            $aiConfig = $space->aiConfigs()->find($configId);

            if (!$aiConfig) {
                $aiConfig = $space->defaultAiConfig;
            }
        }

        if (!$aiConfig) {
            $aiConfig = $space->defaultAiConfig;
        }

        $prompt = $request->string('prompt');
        $tree = $request->input('tree', []);
        $mentions = $request->input('mentions', []);

        return new StreamedResponse(function () use ($space, $prompt, $tree, $mentions, $aiConfig) {
            if (ob_get_level() == 0) {
                ob_start();
            }

            $lastActivity = time();
            $pingInterval = 15;

            echo ": ping\n\n";
            ob_flush();
            flush();

            try {
                foreach ($this->treeAiService->stream($space, $prompt, $tree, $mentions, $aiConfig) as $event) {
                    $now = time();

                    if ($now - $lastActivity >= $pingInterval) {
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
                echo 'data: ' . json_encode([
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ]) . "\n\n";
                ob_flush();
                flush();
            }

            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'close',
        ]);
    }
}
