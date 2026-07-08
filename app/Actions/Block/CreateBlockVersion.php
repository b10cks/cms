<?php

namespace App\Actions\Block;

use App\Models\Space\Block;
use App\Models\Space\BlockVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateBlockVersion
{
    public function execute(Block $block, ?string $commitMessage = null, ?User $user = null): BlockVersion
    {
        return DB::transaction(function () use ($block, $commitMessage, $user) {
            $latestVersion = BlockVersion::where('block_id', $block->id)
                ->orderBy('created_at', 'desc')
                ->first();

            $versionData = [
                'external_id' => $block->external_id,
                'slug' => $block->slug,
                'name' => $block->name,
                'icon' => $block->icon,
                'color' => $block->color,
                'description' => $block->description,
                'type' => $block->type,
                'preview_template' => $block->preview_template,
                'preview_file' => $block->preview_file,
                'schema' => $block->schema->toArray(),
                'editor' => $block->editor,
                'tags' => $block->tags,
                'folder_id' => $block->folder_id,
            ];

            $version = BlockVersion::create([
                'block_id' => $block->id,
                'parent_id' => $latestVersion?->id,
                'created_by_id' => $user?->id,
                'data' => $versionData,
                'commit_message' => $commitMessage,
            ]);

            return $version;
        });
    }
}
