<?php

use App\Enums\Edition;
use App\Services\Ai\Assets\AssetTriage;
use App\Services\Ai\Metadata\MetadataTriage;
use App\Services\Ai\Translation\TranslationTriage;

return [
    // "space" provisions a metered OpenRouter key per space (SaaS). "single"
    // shares the platform key across all spaces, the self-hosted default.
    // Read the edition from env directly: config('edition') may not be loaded yet.
    'mode' => env('AI_MODE', env('B10CKS_EDITION') === Edition::SELF_HOSTED->value ? 'single' : 'space'),

    'default' => env('AI_DEFAULT_DRIVER', 'openrouter'),

    // Asset classification jobs per space per minute; keeps mass runs under
    // the provider's request limits.
    'asset_classification_per_minute' => (int) env('AI_ASSET_CLASSIFICATION_PER_MINUTE', 30),

    // Agent run budgets. Skills may override max_steps, max_tokens and max_usd;
    // a hit completes the run as partial. The aiCredit quota stays the outer limit.
    'runs' => [
        'max_steps' => (int) env('AI_RUN_MAX_STEPS', 12),
        'max_tokens' => (int) env('AI_RUN_MAX_TOKENS', 400000),
        'max_usd' => (float) env('AI_RUN_MAX_USD', 2.0),
        // Total wall time of a run, inline and background together.
        'max_seconds' => (int) env('AI_RUN_MAX_SECONDS', 1800),
        // FrankenPHP kills requests at 59s, so inline runs hand over to the
        // queue before that.
        'inline_seconds' => (int) env('AI_RUN_INLINE_SECONDS', 45),
        // One RunAgentJob advances at most this long, then re-dispatches.
        // The check runs between provider calls, so a call started just in time
        // has the worker's 900s job timeout minus this slice to finish.
        'background_slice_seconds' => (int) env('AI_RUN_BACKGROUND_SLICE_SECONDS', 300),
        // Exclusive ownership of a run. Must exceed RunAgentJob's timeout: a quiet
        // provider stream sends no heartbeat, and a live worker must never look dead.
        // A killed worker's run therefore waits this long before it recovers.
        'lease_seconds' => (int) env('AI_RUN_LEASE_SECONDS', 960),
        // Inline streams die with their request, well before this.
        'inline_lease_seconds' => (int) env('AI_RUN_INLINE_LEASE_SECONDS', 300),
        // A run with no lease and no activity for this long has lost its job.
        'recovery_grace_seconds' => (int) env('AI_RUN_RECOVERY_GRACE_SECONDS', 300),
        // Workers a run may lose before it fails instead of repeating the same work.
        'max_interruptions' => (int) env('AI_RUN_MAX_INTERRUPTIONS', 3),
        // A background run retries transient provider failures on its own, then
        // fails and pauses its operation. Defaults wait 60s, 120s and 240s. A
        // Retry-After header can lengthen a wait up to the maximum.
        'provider_retries' => [
            // Consecutive retries without an answered call.
            'max_attempts' => (int) env('AI_RUN_PROVIDER_RETRY_ATTEMPTS', 3),
            'backoff_seconds' => (int) env('AI_RUN_PROVIDER_RETRY_BACKOFF_SECONDS', 60),
            'max_backoff_seconds' => (int) env('AI_RUN_PROVIDER_RETRY_MAX_BACKOFF_SECONDS', 300),
            // Interrupted calls a run may repeat. Each can be billed with no usage to show for it.
            'max_unconfirmed_calls' => (int) env('AI_RUN_PROVIDER_RETRY_MAX_UNCONFIRMED_CALLS', 2),
        ],
        'max_output_tokens' => (int) env('AI_RUN_MAX_OUTPUT_TOKENS', 16384),
        // Corrections asked for when a final answer misses the skill's output schema.
        'max_output_corrections' => 2,
        // Background runs one space may have going at once; more are refused.
        'max_concurrent_background' => (int) env('AI_RUN_MAX_CONCURRENT_BACKGROUND', 2),
        // Stored stream events only serve live replay and catch-up; the
        // transcript stays on the run. They are pruned this many days after
        // a run finished.
        'event_retention_days' => (int) env('AI_RUN_EVENT_RETENTION_DAYS', 30),
    ],

    // Earlier turns and proposals a follow-up run in a thread sees, estimated
    // at four characters per token. The oldest turns drop first.
    'threads' => [
        'history_tokens' => (int) env('AI_THREAD_HISTORY_TOKENS', 6000),
    ],

    // Files attached to a message. Documents reach the model as extracted
    // text, capped per file; unsent uploads are pruned after a day.
    'attachments' => [
        'max_files' => 10,
        'image_kb' => 5120,
        'document_kb' => 20480,
        'document_chars' => (int) env('AI_ATTACHMENT_DOCUMENT_CHARS', 50000),
        'prune_after_hours' => 24,
    ],

    // Entries one request may change before it must run in the background
    // after a preflight estimate and confirmation.
    'inline_entry_limit' => (int) env('AI_INLINE_ENTRY_LIMIT', 5),

    // Content operations: an approved plan over a frozen scope, worked in
    // bounded batch runs. A larger scope is refused, never cut.
    'operations' => [
        'max_items' => (int) env('AI_OPERATION_MAX_ITEMS', 10000),
        'batch_size' => (int) env('AI_OPERATION_BATCH_SIZE', 20),
        // Batches an entry may sit in without an outcome before it fails.
        'max_attempts' => 2,
        // Entries a planning run reads, spread over the scope's groups.
        'plan_sample' => 20,
        // Items a review group accept or reject decides within the request; larger groups go to the queue.
        'review_sync_limit' => 200,
        // OperationTriage class per kind, for outcomes code can decide without a model call.
        'triage' => [
            'translation' => TranslationTriage::class,
            'assets' => AssetTriage::class,
            'general' => MetadataTriage::class,
        ],
    ],

    'drivers' => [
        'openai' => [
            'enabled' => env('OPENAI_ENABLED', false),
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'models' => [
                [
                    'id' => 'gpt-4o',
                    'name' => 'GPT-4o',
                    'description' => 'Most capable GPT-4 model, optimized for speed and intelligence',
                    'context_window' => ['input' => 128000, 'output' => 16384],
                    'input_cost' => 2.50,
                    'output_cost' => 10.00,
                    'capabilities' => ['text', 'vision', 'tools'],
                    'supports_streaming' => true,
                    'supports_tools' => true,
                    'supports_vision' => true,
                ],
                [
                    'id' => 'gpt-4o-mini',
                    'name' => 'GPT-4o Mini',
                    'description' => 'Affordable and intelligent small model for fast tasks',
                    'context_window' => ['input' => 128000, 'output' => 16384],
                    'input_cost' => 0.15,
                    'output_cost' => 0.60,
                    'capabilities' => ['text', 'vision', 'tools'],
                    'supports_streaming' => true,
                    'supports_tools' => true,
                    'supports_vision' => true,
                ],
                [
                    'id' => 'gpt-4-turbo',
                    'name' => 'GPT-4 Turbo',
                    'description' => 'Previous generation GPT-4 with vision capabilities',
                    'context_window' => ['input' => 128000, 'output' => 4096],
                    'input_cost' => 10.00,
                    'output_cost' => 30.00,
                    'capabilities' => ['text', 'vision', 'tools'],
                    'supports_streaming' => true,
                    'supports_tools' => true,
                    'supports_vision' => true,
                ],
                [
                    'id' => 'o1',
                    'name' => 'o1',
                    'description' => 'Reasoning model for complex problem-solving',
                    'context_window' => ['input' => 200000, 'output' => 100000],
                    'input_cost' => 15.00,
                    'output_cost' => 60.00,
                    'capabilities' => ['text', 'reasoning'],
                    'supports_streaming' => true,
                    'supports_tools' => false,
                    'supports_vision' => false,
                ],
                [
                    'id' => 'o1-mini',
                    'name' => 'o1 Mini',
                    'description' => 'Fast and affordable reasoning model',
                    'context_window' => ['input' => 128000, 'output' => 65536],
                    'input_cost' => 1.10,
                    'output_cost' => 4.40,
                    'capabilities' => ['text', 'reasoning'],
                    'supports_streaming' => true,
                    'supports_tools' => false,
                    'supports_vision' => false,
                ],
            ],
        ],

        'bedrock' => [
            'enabled' => env('BEDROCK_ENABLED', false),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'models' => [
                [
                    'id' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
                    'name' => 'Claude 3.5 Sonnet v2',
                    'description' => 'Anthropic\'s most intelligent model',
                    'context_window' => ['input' => 200000, 'output' => 8192],
                    'input_cost' => 3.00,
                    'output_cost' => 15.00,
                    'capabilities' => ['text', 'vision', 'tools'],
                    'supports_streaming' => true,
                    'supports_tools' => true,
                    'supports_vision' => true,
                ],
                [
                    'id' => 'anthropic.claude-3-5-haiku-20241022-v1:0',
                    'name' => 'Claude 3.5 Haiku',
                    'description' => 'Fast and efficient model for everyday tasks',
                    'context_window' => ['input' => 200000, 'output' => 8192],
                    'input_cost' => 0.25,
                    'output_cost' => 1.25,
                    'capabilities' => ['text', 'vision', 'tools'],
                    'supports_streaming' => true,
                    'supports_tools' => true,
                    'supports_vision' => true,
                ],
                [
                    'id' => 'anthropic.claude-3-opus-20240229-v1:0',
                    'name' => 'Claude 3 Opus',
                    'description' => 'Powerful model for complex tasks',
                    'context_window' => ['input' => 200000, 'output' => 4096],
                    'input_cost' => 15.00,
                    'output_cost' => 75.00,
                    'capabilities' => ['text', 'vision', 'tools'],
                    'supports_streaming' => true,
                    'supports_tools' => true,
                    'supports_vision' => true,
                ],
            ],
        ],

        'openrouter' => [
            'enabled' => env('OPENROUTER_ENABLED', false),
            'api_key' => env('OPENROUTER_API_KEY'),
            'management_key' => env('OPENROUTER_MANAGEMENT_KEY'),
            'site_url' => env('OPENROUTER_SITE_URL', config('app.url')),
            'site_name' => env('OPENROUTER_SITE_NAME', config('app.name')),
            'models' => [],
            // Reset cadence for per-space spend limits. The reissue command
            // refreshes keys at the start of each period: daily|weekly|monthly|annually.
            'key_reset' => env('OPENROUTER_KEY_RESET', 'monthly'),
        ],
    ],

    // Models that cannot switch reasoning off, so "off" is sent as "low".
    // No provider API reports this. Matched with Str::is against OpenRouter
    // style ids (`openai/o3`); the OpenAI driver prefixes its ids with
    // `openai/`. `optional` lists exceptions to `required`.
    'reasoning' => [
        'required' => [
            'openai/o*',
            'openai/gpt-5*',
            'deepseek/deepseek-r1*',
            '*-thinking*',
        ],
        'optional' => [
            'openai/gpt-5*-chat*',
        ],
    ],

    'cache' => [
        'models_ttl' => env('AI_MODELS_CACHE_TTL', 43200),
    ],
];
