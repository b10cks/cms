<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Services\Ai\Contracts\AiToolInterface;

class GetBlockListTool implements AiToolInterface
{
    protected ?Space $space = null;
    protected array $types = ['nestable', 'universal'];

    public function setSpace(Space $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function setTypes(array $types): self
    {
        $this->types = $types;

        return $this;
    }

    public function name(): string
    {
        return 'get_block_list';
    }

    public function description(): string
    {
        return 'Returns a lightweight catalogue of available blocks. Use this to discover which blocks are available before fetching full schemas.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tag_filter' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional array of tags to filter blocks by',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): mixed
    {
        $space = $this->space ?? app('currentSpace');

        if (!$space) {
            \Log::warning('GetBlockListTool: No space context available');

            return [];
        }

        \Log::info('GetBlockListTool: Executing', [
            'space_id' => $space->id,
            'input' => $input,
        ]);

        $query = Block::whereIn('type', $this->types);

        if (!empty($input['tag_filter'])) {
            $query->where(function ($q) use ($input) {
                foreach ($input['tag_filter'] as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }

        $blocks = $query->get();

        \Log::info('GetBlockListTool: Found blocks', ['count' => $blocks->count()]);

        return $blocks->map(fn(Block $block) => [
            'id' => $block->id,
            'slug' => $block->slug,
            'name' => $block->name,
            'description' => $block->description,
            'tags' => $block->tags ?? [],
        ])->values()->toArray();
    }
}
