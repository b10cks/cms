<?php

namespace App\Services\Content\Schema;

class FieldVisibilityPruner
{
    public function prune(ContentSchemaTree $tree): array
    {
        $sanitized = $tree->rawContent;

        foreach ($tree->nodes as $key => $node) {
            if (! $node->visible) {
                unset($sanitized[$key]);
                continue;
            }

            if ($node->field->getType() !== 'blocks') {
                continue;
            }

            if (! is_array($node->rawValue)) {
                continue;
            }

            $items = array_values($node->rawValue);

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (isset($node->childTreesByRawIndex[$index])) {
                    $items[$index] = $this->prune($node->childTreesByRawIndex[$index]);
                }
            }

            $sanitized[$key] = $items;
        }

        return $sanitized;
    }
}
