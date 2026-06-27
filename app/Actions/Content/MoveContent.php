<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentPositionService;
use Illuminate\Support\Facades\DB;

class MoveContent
{
    public function __construct(
        protected ContentHierarchyValidator $contentHierarchyValidator,
        protected ContentPositionService $contentPositionService,
    ) {}

    public function execute(Content $content, ?string $parentId, ?int $position, Space $space): void
    {
        DB::transaction(function () use ($content, $parentId, $position, $space) {
            $content->loadMissing('block');

            // Validate parent exists if provided
            $parent = null;
            if ($parentId !== null) {
                $parent = Content::query()->with('block')->find($parentId);
                if (! $parent || $parent->id === $content->id) {
                    throw new \InvalidArgumentException('Invalid parent content');
                }

                // Prevent circular references
                if ($this->wouldCreateCircularReference($content, $parent)) {
                    throw new \InvalidArgumentException('Cannot move content: would create circular reference');
                }
            }

            $this->contentHierarchyValidator->validatePlacement(
                $space,
                $content->block,
                $parent,
                $content,
                $content->language_iso,
            );

            // Update parent
            $content->parent_id = $parentId;
            $content->save();

            // Resequence siblings only when manual sorting is enabled for the space;
            // otherwise the move is a pure reparent and ordering stays alphabetical.
            if ($space->settings->isContentSortingEnabled()) {
                $this->contentPositionService->moveItemToPosition($content, $parentId, $position);
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

            if ($current->parent_id === null) {
                return false;
            }

            $current = $current->relationLoaded('parent')
                ? $current->getRelation('parent')
                : Content::query()
                    ->whereNull('deleted_at')
                    ->select(['id', 'parent_id'])
                    ->find($current->parent_id);
        }

        return false;
    }
}
