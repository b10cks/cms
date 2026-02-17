<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Support\Facades\DB;

class MoveContent
{
    public function execute(Content $content, ?string $parentId, ?int $position, Space $space): void
    {
        DB::transaction(function () use ($content, $parentId, $position, $space) {
            // Validate parent exists if provided
            if ($parentId !== null) {
                $parent = Content::find($parentId);
                if (!$parent || $parent->id === $content->id) {
                    throw new \InvalidArgumentException('Invalid parent content');
                }

                // Prevent circular references
                if ($this->wouldCreateCircularReference($content, $parent)) {
                    throw new \InvalidArgumentException('Cannot move content: would create circular reference');
                }
            }

            $oldParentId = $content->parent_id;

            // Update parent
            $content->parent_id = $parentId;
            $content->save();

            // Reorder siblings if position is specified
            if ($position !== null) {
                $this->reorderSiblings($content, $position);
            }

            $space->touch('content_updated_at');
        });
    }

    protected function wouldCreateCircularReference(Content $content, Content $newParent): bool
    {
        $current = $newParent;

        while ($current !== null) {
            if ($current->id === $content->id) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    protected function reorderSiblings(Content $content, int $position): void
    {
        // Get all siblings (including the moved content)
        $siblings = Content::where('parent_id', $content->parent_id)
            ->where('id', '!=', $content->id)
            ->orderBy('position')
            ->get();

        // Insert the content at the specified position
        $reordered = $siblings->slice(0, $position)
            ->push($content)
            ->merge($siblings->slice($position));

        // Update positions
        $reordered->each(function ($item, $index) {
            if ($item->position !== $index) {
                $item->position = $index;
                $item->saveQuietly();
            }
        });
    }
}
