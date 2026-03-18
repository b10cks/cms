<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentI18nResolver;

class TransformContentToSearchable
{
    public function __construct(
        private readonly ContentI18nResolver $contentI18nResolver,
    ) {}

    public function execute(Content $content, Space $space): string
    {
        $contentData = $this->contentI18nResolver
            ->resolve($space, $content, $content->language_iso, 'published')
            ->effectiveContent;

        if (empty($contentData)) {
            return '';
        }

        $textParts = [];
        $this->extractTextFromStructure($contentData, $textParts);

        return implode("\n", array_filter($textParts));
    }

    protected function extractTextFromStructure(array $data, array &$textParts): void
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $this->extractTextFromStructure($value, $textParts);
            } elseif (\is_string($value) && ! empty(trim($value))) {
                if (! $this->isSystemField($key)) {
                    $cleaned = $this->cleanText($value);
                    if (! empty($cleaned)) {
                        $textParts[] = $cleaned;
                    }
                }
            }
        }
    }

    protected function isSystemField(string $key): bool
    {
        $systemFields = ['id', 'uuid', 'type', 'component', 'plugin', '_uid'];

        return \in_array($key, $systemFields) || \str_starts_with($key, '_');
    }

    protected function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
