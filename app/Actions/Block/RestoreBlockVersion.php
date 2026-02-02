<?php

namespace App\Actions\Block;

use App\Models\Space\Block;
use App\Models\Space\BlockVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestoreBlockVersion
{
    public function execute(BlockVersion $version): Block
    {
        return DB::transaction(function () use ($version) {
            $block = $version->block;
            $versionData = $version->data;

            $currentVersion = BlockVersion::where('block_id', $block->id)
                ->orderBy('created_at', 'desc')
                ->first();

            BlockVersion::create([
                'block_id' => $block->id,
                'parent_id' => $currentVersion?->id,
                'created_by_id' => auth()->id(),
                'data' => [
                    'external_id' => $block->external_id,
                    'slug' => $block->slug,
                    'name' => $block->name,
                    'icon' => $block->icon,
                    'color' => $block->color,
                    'description' => $block->description,
                    'type' => $block->type,
                    'preview_template' => $block->preview_template,
                    'schema' => $block->schema,
                    'editor' => $block->editor,
                    'tags' => $block->tags,
                    'folder_id' => $block->folder_id,
                ],
                'commit_message' => 'Auto-saved before restore',
            ]);

            $block->fill([
                'external_id' => $versionData['external_id'] ?? $block->external_id,
                'slug' => $versionData['slug'] ?? $block->slug,
                'name' => $versionData['name'] ?? $block->name,
                'icon' => $versionData['icon'] ?? $block->icon,
                'color' => $versionData['color'] ?? $block->color,
                'description' => $versionData['description'] ?? $block->description,
                'type' => $versionData['type'] ?? $block->type,
                'preview_template' => $versionData['preview_template'] ?? $block->preview_template,
                'schema' => $versionData['schema'] ?? $block->schema,
                'editor' => $versionData['editor'] ?? $block->editor,
                'tags' => $versionData['tags'] ?? $block->tags,
                'folder_id' => $versionData['folder_id'] ?? $block->folder_id,
            ]);

            if (! $block->save()) {
                Log::error('Failed to restore block version', [
                    'block_id' => $block->id,
                    'version_id' => $version->id,
                ]);
                throw new \Exception('Failed to restore block version');
            }

            return $block->load('folder');
        });
    }
}
