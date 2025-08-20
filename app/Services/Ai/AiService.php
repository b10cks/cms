<?php

namespace App\Services\Ai;

abstract class AiService
{
    public function metaTags($page)
    {
        $data = json_encode($page);
//        $language = data_get($space->settings, 'default_language.iso', 'en');

        $prompt = <<<TXT
Generate SEO-optimized meta tags for the following page content.

ANALYSIS INSTRUCTIONS:
1. Extract the main topic, purpose, and key information from the page content
2. Identify the target audience and user intent
3. Determine the most compelling value proposition
4. Extract relevant keywords naturally present in the content
5. Consider search intent and competitive differentiation

SEO OPTIMIZATION RULES:
- Title: Compelling, keyword-rich, includes primary topic (60 chars max)
- Description: Action-oriented, includes benefits/value prop (155 chars max)
- Open Graph: Optimized for social sharing engagement
- Use active voice and compelling language
- Avoid keyword stuffing - prioritize natural readability
- Include emotional triggers where appropriate (urgency, benefit, curiosity)

TECHNICAL REQUIREMENTS:
- Respond with ONLY valid JSON (no markdown, explanations, or comments)
- All fields are required (use empty string "" if content insufficient)
- Ensure proper character encoding for special characters
- Title and OG title can be similar but not identical

FALLBACK HANDLING:
- If content is sparse, focus on available information
- If no clear topic, create generic but relevant tags
- Maintain professional tone for business content

Expected JSON structure for response (RESPOND ONLY WITH THIS JSON STRUCTURE without any additional text or markup):
{
  "title": "",
  "description": "",
  "ogTitle": "",
  "ogDescription": "",
}

Page content to analyze:
$data
TXT;

        $result = $this->invokeModel($prompt);
        if ($result === null) {
            return [];
        }

        return json_decode($result, false);
    }

    public function translate($source, $target, $data)
    {
        $prompt = <<<TXT
Translate the following texts from {$source} to {$target}.

IMPORTANT INSTRUCTIONS:
- Preserve meaning, context and intent of the original text
- Adapt source language idioms to natural expressions in the target language
- Maintain any HTML formatting or placeholders present in the original text
- Ensure proper grammar, punctuation, and capitalization in the target language
- Respect the register (formal/informal) of the original content
- RESPOND ONLY WITH VALID JSON - no explanations, comments, or additional text

Input format example:
{
  "field-id-1": "Text to translate",
  "field-id-2": "Another text to translate with <b>formatting</b>"
}

Expected output format (RESPOND ONLY WITH THIS JSON STRUCTURE):
{
  "field-id-1": "Translated text",
  "field-id-2": "Translated text with <b>formatting</b>"
}

Source texts to translate:

TXT;

        $result = $this->invokeModel($prompt . json_encode($data));
        if ($result === null) {
            return [];
        }

        return json_decode($result, false);
    }

    abstract protected function invokeModel($prompt);
}
