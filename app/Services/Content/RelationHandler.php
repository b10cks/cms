<?php

namespace App\Services\Content;

use App\Models\Space\Block;
use App\Services\Content\Schema\ContentSchemaBuilder;

class RelationHandler
{
    public function __construct(
        private readonly ContentSchemaBuilder $schemaBuilder,
    ) {}

    public function extractContentRelations(Block $block, array $data): array
    {
        return collect($this->schemaBuilder->build($block, $data, $data)->flatten())
            ->filter(fn ($node) => $node->field->getType() === 'references')
            ->flatMap(function ($node): array {
                if (! \is_array($node->rawValue)) {
                    return [];
                }

                return array_values(
                    array_filter(
                        $node->rawValue,
                        static fn (mixed $value): bool => \is_string($value) && $value !== '',
                    )
                );
            })
            ->unique()
            ->values()
            ->all();
    }
}
