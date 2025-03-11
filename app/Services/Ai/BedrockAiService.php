<?php

namespace App\Services\Ai;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use PostHog\PostHog;

class BedrockAiService extends AiService
{
    protected BedrockRuntimeClient $client;

    public function __construct()
    {
        $this->client = new BedrockRuntimeClient([
            'region' => config('services.bedrock.region'),
            'version' => 'latest',
            'credentials' => [
                'key' => config('services.bedrock.key'),
                'secret' => config('services.bedrock.secret'),
            ],
        ]);
    }

    protected function invokeModel($prompt)
    {
        $start = microtime(true);
        $messages = [[
            'role' => 'user',
            'content' => $prompt,
        ]];
        $response = $this->client->invokeModel([
            'modelId' => 'anthropic.claude-3-haiku-20240307-v1:0',
            'body' => json_encode([
                'anthropic_version' => 'bedrock-2023-05-31',
                'max_tokens' => 1024,
                'temperature' => 0.5,
                'messages' => $messages
            ]),
            'accept' => 'application/json',
            'contentType' => 'application/json'
        ]);
        $duration = microtime(true) - $start;
        $result = json_decode($response['body'], true);

        PostHog::capture([
            'distinctId' => auth()->id(),
            'event' => '$ai_generation',
            'properties' => [
                '$ai_trace_id' => data_get($result, 'id'),
                '$ai_model' => 'claude-3-haiku-20240307-v1',
                '$ai_provider' => 'bedrock',
                '$ai_input_tokens' => data_get($result, 'usage.input_tokens'),
                '$ai_output_tokens' => data_get($result, 'usage.output_tokens'),
                '$ai_latency' => $duration,
                'response' => data_get($result, 'content.0.text'),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);

        return data_get($result, 'content.0.text');
    }
}
