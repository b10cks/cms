<?php

namespace App\Services\Ai\Prompts;

/**
 * Builders for the user-role prompts shared across the AI endpoints. Keeping
 * the exact wording here (rather than copy-pasted into each controller) means
 * the translation and meta-tag instructions stay consistent between their
 * streaming and non-streaming variants.
 */
class UserPromptBuilder
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public static function translation(string $source, string $target, array $fields): string
    {
        return "Translate the following texts from {$source} to {$target}.\n"
            ."Return only the translated flat JSON object.\n\n"
            .json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function metaTags(string $language, mixed $context): string
    {
        return "Target language: {$language}\n"
            ."Important: All generated meta tag fields must be written strictly in {$language}. "
            ."Do not return English unless {$language} is English.\n\n"
            ."Page content to analyze:\n"
            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
