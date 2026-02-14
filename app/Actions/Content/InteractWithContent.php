<?php

namespace App\Actions\Content;

use App\Http\Resources\Management\BlockAiUseResource;
use App\Http\Resources\Management\BlockResource;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Ai\OpenAiService;

class InteractWithContent
{
    public function __construct(
        protected OpenAiService $aiService
    ) {
    }

    public function execute(string $prompt, Content $content, Space $space, array $files = []): array
    {
        $this->aiService->setSpace($space);

        $fullPrompt = $this->buildPrompt($prompt, $content, $files);

        \Log::info($fullPrompt);

        $result = $this->aiService->generate($fullPrompt);

        if ($result === null) {
            return [];
        }

        return json_decode($result, true) ?? [];
    }

    protected function buildPrompt(string $userPrompt, Content $content, array $files): string
    {
        $content->load(['block']);

        $rootBlock = $content->block;
        $nestableBlocks = $this->getNestableBlocks();

        $systemPrompt = $this->getSystemPrompt();

        $rootBlockJson = json_encode(new BlockAiUseResource($rootBlock));
        $nestableBlocksJson = json_encode(BlockAiUseResource::collection($nestableBlocks));
        $contentJson = json_encode($content->current_version?->content ?? []);

        $filesContext = '';
        if (!empty($files)) {
            $filesContext = "\n\nAttached files:\n" . json_encode($files);
        }

        return <<<TXT
{$systemPrompt}

## User Prompt
{$userPrompt}

## Current root block Information:
{$rootBlockJson}

## Possible Blocks to use:
{$nestableBlocksJson}

## Current content:
{$contentJson}
TXT;
    }

    protected function getNestableBlocks()
    {
        return Block::whereIn('type', ['nestable', 'universal'])
            ->get();
    }

    protected function getSystemPrompt(): string
    {
        return <<<'TXT'
You are an expert web content architect specializing in block-based headless CMS systems.

Your task is to fulfill the user's request by producing a modified version of the current content JSON.

## Core Principles

- Output ONLY valid, modified content JSON — no explanations, no markdown, no prose
- Preserve all existing fields, IDs, and structural metadata unless the user explicitly requests changes
- Never invent block types; use only blocks from the "Possible Blocks" list
- Never place a block inside a field that does not permit it — enforce whitelist rules strictly

## Understanding the Data Model

The content is structured as a tree:
- The **root block** defines the top-level layout and its available fields
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

- Prefer semantic precision over verbosity: choose the right block type rather than forcing content into a generic one
- Maintain consistent tone, voice, and style with the surrounding unmodified content
- Do not remove content unless the user explicitly asks for removal
- If the request is ambiguous, interpret it in the way that produces the most useful, complete result
- Answer with a **complete** and **valid** structure of the root block!

## Example Structure
```
{"body":[{"id":"01k82zp8yypsvsdge0961s2k3h","align":"center","block":"hero","valign":"center","content":[{"id":"01k82zpt6gqw20wnsj7k2za5w1","size":"xl","align":"center","block":"simpletext","header":"Kontakt","layout":"default","bodytext":"","subheader":""}],"background":{"id":"01k4f70132jfa1trgejndt7rdx","url":"","data":[],"size":811673,"type":"asset","filename":"ordination_kliebergasse_outside","extension":"jpeg","full_path":"","mime_type":"image/jpeg"}},{"id":"01k82z81jq4drjjrejz5gtrnsz","top":true,"block":"section","theme":"white","bottom":false,"content":[{"id":"01k82z860grrgbhdzj70a8k3nc","block":"sideBySide","fullA":false,"_isCut":true,"columnA":[{"id":"01k82z860grrgbhdzj70a8k3nd","block":"iframeEmbed","source":""}],"columnB":[{"id":"01k82z91nedncc0n9v3bx9jttx","block":"simpletext","header":"","bodytext":""}]}],"padding":"xl"}]}
```
TXT;
    }
}
