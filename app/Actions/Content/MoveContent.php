<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentPositionService;
use App\Services\Content\Serial\ContentSerialAssigner;

class MoveContent
{
    public function __construct(
        protected ContentHierarchyValidator $contentHierarchyValidator,
        protected ContentPositionService $contentPositionService,
        protected ContentSerialAssigner $serialAssigner,
    ) {}

    public function execute(Content $content, ?string $parentId, ?int $position, Space $space): void
    {
        $content->getConnection()->transaction(function () use ($content, $parentId, $position, $space) {
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

            $previousParentId = $content->parent_id;

            // Update parent
            $content->parent_id = $parentId;
            $content->save();

            if ($previousParentId !== $parentId) {
                $this->reallocateSerials($content, $parent, $space);
            }

            // Resequence siblings only when manual sorting is enabled for the space;
            // otherwise the move is a pure reparent and ordering stays alphabetical.
            if ($space->settings->isContentSortingEnabled()) {
                $this->contentPositionService->moveItemToPosition($content, $parentId, $position);
            }

            $space->touch('content_updated_at');
        });
    }

    /**
     * Serials default to `on_move: keep` — an identifier that changes when
     * something is filed elsewhere is not an identifier. Only fields explicitly
     * configured to reallocate are renumbered, and the new value is written as
     * a new version so the change is visible in the entry's history.
     */
    protected function reallocateSerials(Content $content, ?Content $parent, Space $space): void
    {
        $content->loadMissing('current_version');

        // Deliberately not `$content->block`: saving the entry fires
        // ContentUpdated, which re-loads the relation as `block:id,type,icon,color`.
        // The partial model has no schema, so trusting it here would find no
        // serial fields and silently skip reallocation — invisibly so in
        // production, where missing attributes do not throw.
        $block = Block::query()->find($content->block_id);

        if (! $block) {
            return;
        }

        $assigned = $this->serialAssigner->reallocateOnMove($space, $block, $content, $parent);

        if ($assigned === []) {
            return;
        }

        $version = ContentVersion::createWithContentContext([
            'message' => 'Serial reallocated after move',
            'content_id' => $content->id,
            'parent_id' => $content->current_version_id,
            'content' => array_replace($content->getCurrentContent(), $assigned),
            'created_by_id' => $content->current_version?->created_by_id,
        ], $content);

        $content->current_version_id = $version->id;
        $content->save();
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
