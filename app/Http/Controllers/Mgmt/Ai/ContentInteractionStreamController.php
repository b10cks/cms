<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Models\Space\Content;
use App\Models\Traits\SpaceFromQuery;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\ModelRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentInteractionStreamController extends Controller
{
    use SpaceFromQuery;

    public function __construct(
        protected AiStreamService $streamService,
        protected ModelRegistry $registry
    ) {
    }

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'prompt' => 'required|string',
            'content' => 'sometimes|nullable|array',
            'content_id' => 'sometimes|nullable|string',
            'contentId' => 'sometimes|nullable|string',
            'files' => 'sometimes|array',
            'model' => 'sometimes|nullable|string',
            'mentions' => 'sometimes|array',
            'mentions.*.type' => 'sometimes|string|in:content,block',
            'mentions.*.id' => 'sometimes|string',
            'mentions.*.label' => 'sometimes|string',
        ]);

        $space = $this->getSpaceFromQuery();
        app()->offsetSet('currentSpace', $space);

        $contentId = $request->input('content_id') ?? $request->input('contentId');
        $content = $contentId ? Content::find($contentId) : null;

        if ($model = $request->input('model')) {
            $ai = $space->settings->ai ?? [];
            $ai['model'] = $model;
            $space->settings->ai = $ai;
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

        return new StreamedResponse(function () use ($space, $prompt, $context, $files) {
            if (ob_get_level() == 0) {
                ob_start();
            }

            $lastActivity = time();
            $pingInterval = 15;

            echo ": ping\n\n";
            ob_flush();
            flush();

            try {
                foreach ($this->streamService->stream($space, $prompt, $context, $files) as $event) {
                    $now = time();

                    \Log::info('Stream event', [
                        'type' => $event->type->value,
                        'content_length' => strlen($event->content ?? ''),
                    ]);

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
                        \Log::info('Stream ending', ['type' => $event->type->value]);
                        ob_flush();
                        flush();
                        break;
                    }
                }
            } catch (\Throwable $e) {
                \Log::error('Stream error', ['error' => $e->getMessage()]);
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
