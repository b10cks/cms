<?php

namespace App\Services\Ai\Tools;

use App\Models\Space\Content;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;

/** Reads one entry's current (draft) version. */
class GetEntryTool implements AiToolInterface
{
    public function name(): string
    {
        return 'get_entry';
    }

    public function description(): string
    {
        return 'Reads one content entry by ID: its name, slug, block and the field values of its current draft version.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The content entry ID (ULID).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $input): mixed
    {
        $content = Content::query()->with(['block', 'current_version'])->find((string) ($input['id'] ?? ''));

        if (! $content) {
            throw new ToolError('No entry with this ID.');
        }

        return [
            'id' => $content->id,
            'name' => $content->name,
            'slug' => $content->slug,
            'full_slug' => $content->full_slug,
            'block' => $content->block?->slug,
            'content' => $content->current_version?->content ?? [],
        ];
    }
}
