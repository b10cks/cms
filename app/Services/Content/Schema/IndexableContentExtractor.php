<?php

namespace App\Services\Content\Schema;

class IndexableContentExtractor
{
    public function extract(ContentSchemaTree $tree): string
    {
        $parts = [];
        $this->walk($tree, $parts);

        return implode("\n", array_values(array_filter(array_unique($parts))));
    }

    /**
     * @param array<int, string> $parts
     */
    protected function walk(ContentSchemaTree $tree, array &$parts): void
    {
        foreach ($tree->nodes as $node) {
            if (!$node->visible) {
                continue;
            }

            if ($node->field->isIndexable()) {
                $parts = [...$parts, ...$this->extractNodeText($node)];
            }

            foreach ($node->childTrees as $childTree) {
                if (($childTree->rawContent['hidden'] ?? false) === true) {
                    continue;
                }

                $this->walk($childTree, $parts);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function extractNodeText(ContentSchemaNode $node): array
    {
        return match ($node->field->getType()) {
            'meta' => $this->extractStrings([
                data_get($node->effectiveValue, 'title'),
                data_get($node->effectiveValue, 'description'),
                data_get($node->effectiveValue, 'ogTitle'),
                data_get($node->effectiveValue, 'ogDescription'),
            ]),
            default => $this->extractStrings([$node->effectiveValue]),
        };
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    protected function extractStrings(array $values): array
    {
        $parts = [];

        foreach ($values as $value) {
            if (\is_string($value)) {
                $clean = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

                if ($clean !== '') {
                    $parts[] = $clean;
                }

                continue;
            }

            if (\is_array($value)) {
                $parts = [...$parts, ...$this->extractStrings($value)];
            }
        }

        return $parts;
    }
}
