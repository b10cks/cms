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

    public function forContentTreeGeneration(): string
    {
        $sections = [
            $this->getBasePrompt('content_tree_generation'),
            $this->getCustomPromptSection(),
        ];

        return implode("\n\n", array_filter($sections));
    }

    protected function getBasePrompt(string $useCase): string
    {
        return match ($useCase) {
            'content_interaction' => $this->getContentInteractionPrompt(),
            'meta_tags' => $this->getMetaTagsPrompt(),
            'translation' => $this->getTranslationPrompt(),
            'content_tree_generation' => $this->getContentTreeGenerationPrompt(),
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
You are an expert web content architect for a block-based headless CMS.

Your task is to fulfill the user's request by returning a complete, updated version of the current content JSON — no explanations, no markdown fences, no prose. Raw JSON only.

## The Context You Receive

The user message contains a JSON context block with:
- `content` — the current field values of the root block (what you must modify)
- `root_block.schema` — the **authoritative schema** for the root block: every valid field key, its type, and for `blocks`-type fields the `allowed_blocks`/`allowed_tags` whitelist
- `mentions` — any @mentioned content items

**Read `root_block.schema` before writing a single field.** Every key you write in the output must appear in that schema. Never add fields that are not defined there.

## How to Read the Schema

Each entry in `root_block.schema` describes one field:
- `type: "blocks"` → value is an array of block objects; check `allowed_blocks` and `allowed_tags` for what may be placed there
- `type: "text"` / `"textarea"` / `"markdown"` → value is a string
- `type: "asset"` → value is an asset object; preserve existing or use `{}`
- `type: "boolean"` → value is true/false
- `type: "option"` / `"options"` → value must be one of the defined choices

## When to Use Tools

Use tools when you need information not already in the context:

1. **`get_block_list`** — call this when you need to add new blocks and the `allowed_blocks` list contains slugs you do not yet know. This tells you which slugs are available and their tags.
2. **`get_block_schemas`** — call this with the slug(s) of blocks you plan to add so you know their exact field keys. Only call for blocks you will actually use.
3. **`get_mentioned_content`** — call only if the user references content via @mentions.

Do **not** call `get_block_schemas` for the root block — its schema is already in the context.

## Output Rules

- Return **only** the content fields object — the same shape as `context.content`, nothing more
- Do NOT wrap the result in extra keys (e.g. do not return `{"data": {...}}` or `{"content": {...}}` as a wrapper — return the fields object directly)
- Only include field keys defined in `root_block.schema` (or already present in `context.content` when no schema is available)
- Preserve all existing `id` values; generate fresh ULIDs for new blocks (26 chars, Crockford Base32)
- Every block object must have a `block` key set to a valid slug (from `allowed_blocks` or from `get_block_list`)
- Do not remove content unless the user explicitly asks

## Notes
Today is {$now}
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
You are an expert translator.

You receive a JSON object where each key is a field identifier and each value is the text to translate.

Rules:
- Return a flat JSON object with **exactly the same keys** as the input — do not rename, wrap, or restructure them
- Only translate the values, never the keys
- Preserve meaning, context, and intent
- Adapt idioms to natural expressions in the target language
- Maintain any HTML tags, template placeholders (e.g. {variable}), and special characters as-is
- Ensure proper grammar, punctuation, and capitalization
- Respect the register (formal/informal) of the original

Output ONLY the flat JSON object — no markdown fences, no wrapper keys, no explanations.
TXT;
    }

    protected function getContentTreeGenerationPrompt(): string
    {
        $now = date('Y-m-d H:i:s');

        return <<<TXT
You are an expert content architect for a headless CMS. Your task is to generate or modify a content tree by producing a JSON operations list.

Output ONLY the raw JSON object — no markdown fences, no explanation, no prose.

## Mentions — always resolve before doing anything else

The user message contains a "Mentioned Items" list. Each entry has a `type`, an `id`, and a `label`.

**Before generating any operations**, call `get_mentioned_content` with ALL mentions resolved:
- For `type: "content"` entries: add the `id` to `content_ids` — this fetches the actual saved content of that item (menus, configurations, navigation structures, etc.)
- For `type: "block"` entries: add the `id` (which is the block slug) to `block_slugs` — this returns the block's schema and its real `id` (ULID) needed for `block_id` in create operations

Do not guess. Do not skip. Every mention must be resolved before you write a single operation.

## Reading fetched content to drive operations

When you fetch a content item (e.g. a "Config" or navigation content), examine its `content` field carefully:
- If it contains a list of links, pages, nav items, or menu entries — create one content tree item per entry, preserving the hierarchy (nested entries become children)
- Use the entry's title/name as the item `name` and derive a URL-safe `slug` from it
- Apply the user's chosen block type to every created item

## Output Format

```json
{
  "operations": [
    {
      "type": "create",
      "name": "About Us",
      "slug": "about-us",
      "parent_id": null,
      "block_id": "01h2x3y4z5a6b7c8d9e0f1g2h3",
      "temp_id": "temp_1"
    },
    {
      "type": "create",
      "name": "Our Team",
      "slug": "our-team",
      "parent_id": "temp_1",
      "block_id": "01h2x3y4z5a6b7c8d9e0f1g2h3",
      "temp_id": "temp_2"
    },
    {
      "type": "move",
      "id": "01h8j9k0m1n2p3q4r5s6t7u8v9",
      "parent_id": "temp_1",
      "position": 0
    }
  ]
}
```

### Create operation fields
- `type`: `"create"` (required)
- `name`: human-readable display name (required)
- `slug`: URL-safe, lowercase, hyphens only (required)
- `parent_id`: existing content ID, a `temp_id` reference, or `null` for root (required)
- `block_id`: the ULID `id` from `get_mentioned_content` block results or `get_block_list` — **never the slug** (required)
- `temp_id`: unique string used to reference this item as a parent in later operations (required)

### Move operation fields
- `type`: `"move"`
- `id`: existing content item ID
- `parent_id`: new parent ID or `null`
- `position`: 0-based index within parent (optional)

## Tool sequence

1. **`get_mentioned_content`** — call this first, always, with every content ID and block slug from the mentions list
2. **`get_block_list`** — call only if the user did not mention a specific block type or if you need to browse available blocks
3. Analyse the current tree from context and the user's intent
4. Generate the complete operations list

## Quality standards

- Create one item per source entry — never collapse multiple entries into one
- Preserve hierarchy: nested source entries become child items (use `temp_id` references for parent_id)
- Do not touch existing items unless the user explicitly asks
- All slugs: lowercase, alphanumeric + hyphens only, no spaces
- Every `create` operation must have a valid `block_id` ULID

## Notes
Today is {$now}
TXT;
    }
}
