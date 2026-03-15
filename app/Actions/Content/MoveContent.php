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
}
