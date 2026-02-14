<?php

namespace App\Services\Ai;

use App\Exceptions\AiUsageExceededException;
use App\Models\Management\AiModel;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiUsage;

abstract class AiService
{
    protected ?AiModel $model = null;

    protected ?Space $space = null;

    public function metaTags($page)
    {
        $data = json_encode($page);
        $language = data_get($this->space->settings, 'default_language.iso', 'en');

        $prompt = <<<TXT
Generate SEO-optimized meta tags for the following page content.

CONTEXT:
- Language: {$language}

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

    public function generate(string $prompt): ?string
    {
        return $this->invokeModel($prompt);
    }

    public function setModel(AiModel $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function setSpace(Space $space): self
    {
        $this->space = $space;
        $this->setModelFromSpace($space);

        return $this;
    }

    public function setModelFromSpace(Space $space)
    {
        $modelId = data_get($space->settings, 'ai.model', null);
        $model = $modelId ? AiModel::findOrFail($modelId) : AiModel::where('is_free')->first();
        $this->setModel($model);

        return $this;
    }

    protected function getModelIdentifier()
    {
        return $this->model?->model;
    }

    public function trackUsage(int $usedTokens): SpaceAiUsage
    {
        $usage = SpaceAiUsage::getCurrentUsage($this->space->id);

        if (!$usage) {
            throw new AiUsageExceededException(
                "No active AI usage quota found for space '{$this->space->name}'"
            );
        }

        $tokens = ceil(($this->model->token_multiplier ?? 1) * $usedTokens);
        if (!$usage->canUse($tokens)) {
            $remaining = $usage->remaining_tokens;
            throw new AiUsageExceededException(
                "Insufficient AI tokens. Requested: {$tokens}, Available: {$remaining}"
            );
        }

        $success = $usage->useTokens($tokens);
        if (!$success) {
            throw new AiUsageExceededException(
                'Failed to track AI usage. The quota may have expired or been exceeded by another request.'
            );
        }

        return $usage->fresh();
    }

    protected function checkUsageBeforeInvoke(int $estimatedTokens): SpaceAiUsage
    {
        if (!$this->space || !$this->model) {
            throw new \InvalidArgumentException('Space and model must be set before tracking usage');
        }

        $usage = SpaceAiUsage::getCurrentUsage($this->space->id);

        if (!$usage) {
            throw new AiUsageExceededException(
                "No active AI usage quota found for space '{$this->space->name}'"
            );
        }

        if (!$usage->canUse($estimatedTokens)) {
            $remaining = $usage->remaining_tokens;
            throw new AiUsageExceededException(
                "Insufficient AI tokens. Estimated need: {$estimatedTokens}, Available: {$remaining}"
            );
        }

        return $usage;
    }

    protected function estimateTokens(string $prompt): int
    {
        return intval(strlen($prompt) / 4);
    }
}
