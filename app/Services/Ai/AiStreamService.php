<?php

namespace App\Services\Ai;

use App\Models\Management\Space;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Tools\GetBlockListTool;
use App\Services\Ai\Tools\GetBlockSchemasTool;
use App\Services\Ai\Tools\GetMentionedContentTool;
use App\Services\Ai\Tools\SearchAssetsTool;
use Generator;
use Illuminate\Support\Str;

class AiStreamService
{
    protected ModelRegistry $registry;

    public function __construct(ModelRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function stream(
        Space $space,
        string $prompt,
        array $context = [],
        array $files = []
    ): Generator {
        app()->offsetSet('currentSpace', $space);

        $modelId = $this->resolveModelId($space);
        [$driverName, $modelIdentifier] = $this->parseModelId($modelId);

        $driver = $this->registry->getDriver($driverName);

        if (!$driver) {
            \Log::error('AiStreamService: Driver not found', ['driver' => $driverName]);
            yield StreamEvent::error("Driver '{$driverName}' not found or not enabled");

            return;
        }

        $tools = $this->createTools($space);

        foreach ($tools as $tool) {
            $driver->registerTool($tool);
        }

        $messages = $this->buildMessages($prompt, $context, $files);
        $toolDefinitions = $driver->getToolDefinitions();

        yield from $driver->stream(
            $modelIdentifier,
            $messages,
            $toolDefinitions,
            ['max_tokens' => 32768, 'temperature' => 0.7]
        );
    }

    protected function createTools(Space $space): array
    {
        return [
            (new GetBlockListTool)->setSpace($space),
            (new GetBlockSchemasTool)->setSpace($space),
            (new SearchAssetsTool)->setSpace($space),
            (new GetMentionedContentTool)->setSpace($space),
        ];
    }

    protected function resolveModelId(Space $space): string
    {
        $modelId = $space->settings->ai['model'] ?? null;

        if ($modelId && Str::contains($modelId, ':')) {
            return $modelId;
        }

        foreach ($this->registry->getEnabledDrivers() as $driver) {
            $defaultModel = $driver->getDefaultModel();
            if ($defaultModel) {
                return $defaultModel->getFullId();
            }
        }

        return 'openai:gpt-4o-mini';
    }

    protected function parseModelId(string $fullId): array
    {
        if (Str::contains($fullId, ':')) {
            return explode(':', $fullId, 2);
        }

        return ['openai', $fullId];
    }

    protected function buildMessages(string $prompt, array $context, array $files): array
    {
        $systemPrompt = $this->getSystemPrompt();

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $userContent = $prompt;

        if (!empty($context)) {
            $userContent .= "\n\n## Context\n" . json_encode($context);
        }

        if (!empty($files)) {
            $userContent .= "\n\n## Attached Files\n" . json_encode($files, JSON_PRETTY_PRINT);
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }

    protected function getSystemPrompt(): string
    {
        $now = date('Y-m-d H:i:s');

        return <<<TXT
You are an expert web content architect specializing in block-based headless CMS systems.

Your task is to fulfill the user's request by producing a modified version of the current content JSON.

## Core Principles

- Output ONLY valid, modified content JSON — no explanations, no markdown, no prose
- Preserve all existing fields, IDs, and structural metadata unless the user explicitly requests changes
- Never invent block types; use only blocks from the "Possible Blocks" list
- Never place a block inside a field that does not permit it — enforce whitelist rules strictly

## Understanding the Data Model

The content is structured as a tree:
- Each field may accept one or more **nested blocks**, each with their own field definitions
- Every block has a `type`, a set of typed `fields`, and optionally an `allowed_blocks` whitelist per field
- Tags on blocks (`block_tags`) further restrict placement — a block may only be placed where its tag is whitelisted

## How to Apply the User's Request

1. Read the user prompt and identify the intent: restructure, complete rewrite, expand, translate, tone-shift, etc.
2. Locate the relevant nodes in the current content JSON
3. Apply the necessary changes to fulfill the intent with superior quality
4. Where new blocks are needed, select the most semantically appropriate type from "Possible Blocks"
5. Validate every placement against the field's `allowed_blocks` and `allowed_tags` before including it
6. Return the complete, updated content JSON — not a diff, not a partial — the full structure

## Quality Standards

- All ids must be unique and in ULID format
- Prefer semantic precision over verbosity: choose the right block type rather than forcing content into a generic one
- Maintain consistent tone, voice, and style with the surrounding unmodified content
- Do not remove content unless the user explicitly asks for removal
- If the request is ambiguous, interpret it in the way that produces the most useful, complete result
- Answer with a **complete** and **valid** structure as defined by the schema!

## Example Structure to return
```
{"body":[{"id":"01k82zp8yypsvsdge0961s2k3h","align":"center","block":"hero","valign":"center","content":[{"id":"01k82zpt6gqw20wnsj7k2za5w1","size":"xl","align":"center","block":"simpletext","header":"Kontakt","layout":"default","bodytext":"","subheader":""}],"background":{"id":"01k4f70132jfa1trgejndt7rdx","url":"","data":[],"size":811673,"type":"asset","filename":"ordination_kliebergasse_outside","extension":"jpeg","full_path":"","mime_type":"image/jpeg"}},{"id":"01k82z81jq4drjjrejz5gtrnsz","top":true,"block":"section","theme":"white","bottom":false,"content":[{"id":"01k82z860grrgbhdzj70a8k3nc","block":"sideBySide","fullA":false,"_isCut":true,"columnA":[{"id":"01k82z860grrgbhdzj70a8k3nd","block":"iframeEmbed","source":""}],"columnB":[{"id":"01k82z91nedncc0n9v3bx9jttx","block":"simpletext","header":"","bodytext":""}]}],"padding":"xl"}]}
```

## Notes
Today is {$now}

## Tool Usage

You have access to tools. Use them to gather the information you need before
producing the final content JSON.

Preferred sequence:
1. Call `get_block_list` to discover available blocks (filter by tag if the request implies a specific content type)
2. Call `get_block_schemas` only for the blocks you intend to use
3. Call `search_assets` if the request involves images or media
4. Produce the final content JSON

Do not call `get_block_schemas` for every block — only fetch schemas you need.
TXT;
    }
}
