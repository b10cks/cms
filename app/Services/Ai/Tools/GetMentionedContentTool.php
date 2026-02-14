<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Ai\Contracts\AiToolInterface;

class GetMentionedContentTool implements AiToolInterface
{
    protected ?Space $space = null;

    public function setSpace(Space $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function name(): string
    {
        return 'get_mentioned_content';
    }

    public function description(): string
    {
        return 'Fetches the content field of the current draft version for the given content IDs. Use this when the user mentions specific content items to understand their structure and data.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Array of content IDs (ULIDs) to fetch',
                ],
                'block_slugs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Array of block slugs to fetch schemas for',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): mixed
    {
        $space = $this->space ?? app('currentSpace');

        if (! $space) {
            \Log::warning('GetMentionedContentTool: No space context available');

            return [];
        }

        \Log::info('GetMentionedContentTool: Executing', [
            'space_id' => $space->id,
            'input' => $input,
        ]);

        $result = [
            'contents' => [],
            'blocks' => [],
        ];

        $contentIds = $input['content_ids'] ?? [];
        $blockSlugs = $input['block_slugs'] ?? [];

        if (! empty($contentIds)) {
            $contents = Content::whereIn('id', $contentIds)
                ->with(['block', 'current_version'])
                ->get();

            foreach ($contents as $content) {
                $result['contents'][] = [
                    'id' => $content->id,
                    'name' => $content->name,
                    'slug' => $content->slug,
                    'block' => $content->block?->slug,
                    'content' => $content->current_version?->content ?? $content->content ?? [],
                ];
            }
        }

        if (! empty($blockSlugs)) {
            $blocks = Block::whereIn('slug', $blockSlugs)->get();

            foreach ($blocks as $block) {
                $result['blocks'][] = [
                    'slug' => $block->slug,
                    'name' => $block->name,
                    'description' => $block->description,
                    'schema' => $block->schema,
                ];
            }
        }

        \Log::info('GetMentionedContentTool: Found items', [
            'contents_count' => count($result['contents']),
            'blocks_count' => count($result['blocks']),
        ]);

        return $result;
    }
}
