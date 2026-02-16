<?php

namespace App\Services\Ai\Prompts;

use App\Models\Management\SpaceAiConfig;

class SystemPromptBuilder
{
    public function __construct(
        protected ?SpaceAiConfig $config = null
    ) {}

    public function forContentInteraction(): string
    {
        $sections = [
            $this->getBasePrompt('content_interaction'),
            $this->getCustomPromptSection(),
        ];

        return implode("\n\n", array_filter($sections));
    }

    public function forMetaTags(): string
    {
        $sections = [
            $this->getBasePrompt('meta_tags'),
            $this->getCustomPromptSection(),
        ];

        return implode("\n\n", array_filter($sections));
    }

    public function forTranslation(): string
    {
        $sections = [
            $this->getBasePrompt('translation'),
            ];

        return implode("\n\n", array_filter($sections));
    }

    protected function getBasePrompt(string $useCase): string
    {
        return match ($useCase) {
            'content_interaction' => $this->getContentInteractionPrompt(),
            'meta_tags' => $this->getMetaTagsPrompt(),
            'translation' => $this->getTranslationPrompt(),
            default => '',
        };
    }

    protected function getCustomPromptSection(): string
    {
        $customPrompt = $this->config?->system_prompt;

        if (empty($customPrompt)) {
            return '';
        }

        // Strip potential prompt injection attempts by removing common injection patterns
        $sanitized = $this->sanitizeUserPrompt($customPrompt);

        return <<<TXT
## Space-Specific Behavior & Guidelines

{$sanitized}

---
**Important**: The above guidelines are defined by the space administrator. You must follow these rules while maintaining all core b10cks principles above.
TXT;
    }

    protected function sanitizeUserPrompt(string $prompt): string
    {
        // Strip HTML tags for the system prompt
        $plainPrompt = strip_tags($prompt);

        // Remove common prompt injection patterns
        $patterns = [
            '/ignore\s+(?:all\s+)?previous\s+instructions/i',
            '/disregard\s+(?:all\s+)?previous/i',
            '/forget\s+(?:all\s+)?previous/i',
            '/you\s+are\s+now/i',
            '/from\s+now\s+on/i',
            '/new\s+instructions?:/i',
        ];

        foreach ($patterns as $pattern) {
            $plainPrompt = preg_replace($pattern, '', $plainPrompt);
        }

        return trim($plainPrompt);
    }

    protected function getContentInteractionPrompt(): string
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

- All ids must be unique and in ULID format (26 characters, Crockford Base32, e.g. 01ARZ3NDEKTSV4RRFFQ69G5FAV)
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
4. Call `get_mentioned_content` if the user references other content via @mentions
5. Produce the final content JSON

Do not call `get_block_schemas` for every block — only fetch schemas you need.
TXT;
    }

    protected function getMetaTagsPrompt(): string
    {
        return <<<'TXT'
You are an SEO specialist. Generate optimized meta tags for the given page content.

Respond with ONLY valid JSON in the following structure:
{
  "title": "",
  "description": "",
  "ogTitle": "",
  "ogDescription": ""
}

Rules:
- Title: Compelling, keyword-rich, 60 chars max
- Description: Action-oriented with value proposition, 155 chars max
- OG title and description: Optimized for social sharing
- Use active voice, avoid keyword stuffing
- All fields required (use empty string if content is insufficient)
TXT;
    }

    protected function getTranslationPrompt(): string
    {
        return <<<'TXT'
You are an expert translator. Translate the provided texts while:
- Preserving meaning, context, and intent
- Adapting idioms to natural expressions in the target language
- Maintaining any HTML formatting or placeholders
- Ensuring proper grammar, punctuation, and capitalization
- Respecting the register (formal/informal) of the original

Respond with ONLY valid JSON matching the input structure — no explanations.
TXT;
    }
}
