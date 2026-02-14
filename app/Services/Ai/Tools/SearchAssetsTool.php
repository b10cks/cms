<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Ai\Contracts\AiToolInterface;

class SearchAssetsTool implements AiToolInterface
{
    protected ?Space $space = null;

    public function setSpace(Space $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function name(): string
    {
        return 'search_assets';
    }

    public function description(): string
    {
        return 'Searches the media library for assets. Use this when the user needs images or media files.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query for filename or metadata',
                ],
                'mime_types' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Filter by MIME types (e.g., ["image/jpeg", "image/png"])',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return',
                    'default' => 10,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): mixed
    {
        $space = $this->space ?? app('currentSpace');

        if (!$space) {
            \Log::warning('SearchAssetsTool: No space context available');

            return [];
        }

        \Log::info('SearchAssetsTool: Executing', [
            'space_id' => $space->id,
            'input' => $input,
        ]);

        $query = Asset::query();

        if (!empty($input['query'])) {
            $searchQuery = $input['query'];
            $query->where(function ($q) use ($searchQuery) {
                $q->where('filename', 'like', "%{$searchQuery}%")
                    ->orWhereJsonContains('data->alt', $searchQuery);
            });
        }

        if (!empty($input['mime_types'])) {
            $query->whereIn('mime_type', $input['mime_types']);
        }

        $limit = $input['limit'] ?? 10;
        $assets = $query->limit($limit)->get();

        return $assets->map(fn(Asset $asset) => [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'extension' => $asset->extension,
            'mime_type' => $asset->mime_type,
            'size' => $asset->size,
            'url' => app(\App\Services\Storage\AssetService::class)->getAssetUrl($asset),
            'alt' => $asset->data['alt'] ?? null,
        ])->values()->toArray();
    }
}
