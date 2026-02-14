<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Services\Ai\Contracts\AiToolInterface;

class GetBlockSchemasTool implements AiToolInterface
{
    protected ?Space $space = null;

    public function setSpace(Space $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function name(): string
    {
        return 'get_block_schemas';
    }

    public function description(): string
    {
        return 'Fetches full BlockResource definitions for a specific set of blocks. Use only for blocks you intend to use.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slugs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Array of block slugs to fetch schemas for',
                ],
            ],
            'required' => ['slugs'],
        ];
    }

    public function execute(array $input): mixed
    {
        $space = $this->space ?? app('currentSpace');

        if (!$space) {
            \Log::warning('GetBlockSchemasTool: No space context available');

            return [];
        }

        $slugs = $input['slugs'] ?? [];

        if (empty($slugs)) {
            return [];
        }

        \Log::info('GetBlockSchemasTool: Executing', [
            'space_id' => $space->id,
            'slugs' => $slugs,
        ]);

        $blocks = Block::whereIn('slug', $slugs)
            ->get();

        return $blocks->map(fn(Block $block) => [
            'slug' => $block->slug,
            'type' => $block->type,
            'schema' => $block->schema->toArray(),
            'tags' => $block->tags ?? [],
        ])->values()->toArray();
    }
}
