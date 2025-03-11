<?php

namespace App\Services\Ai;

abstract class AiService
{

    public function translate($source, $target, $data)
    {
        $prompt = <<<TXT
Translate the following texts from ${source} to ${target}.

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
