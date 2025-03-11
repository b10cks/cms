<?php

namespace App\Services\Ai;

use OpenAI\Laravel\Facades\OpenAI;
use PostHog\PostHog;

class OpenAiService extends AiService
{

    protected function invokeModel($prompt)
    {
        $start = microtime(true);
        $result = OpenAI::chat()->create([
            'model' => 'deepseek/deepseek-chat:free',
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
        $duration = microtime(true) - $start;

        PostHog::capture([
            'distinctId' => auth()->id(),
            'event' => '$ai_generation',
            'properties' => [
                '$ai_trace_id' => $result->id,
                '$ai_model' => $result->model,
                '$ai_provider' => 'openai',
                '$ai_input_tokens' => $result->usage->promptTokens,
                '$ai_output_tokens' => $result->usage->completionTokens,
                '$ai_latency' => $duration
            ],
            'timestamp' => now()->toIso8601String(),
        ]);

        return $result->choices[0]->message->content ?? null;
    }
}
