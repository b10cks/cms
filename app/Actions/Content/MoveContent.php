<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentI18nService;
use App\Services\Content\ContentPositionService;
use App\Services\Content\Serial\ContentSerialAssigner;

class MoveContent
{
    public function __construct(
        protected ContentHierarchyValidator $contentHierarchyValidator,
        protected ContentPositionService $contentPositionService,
        protected ContentSerialAssigner $serialAssigner,
        protected ContentI18nService $contentI18nService,
    ) {}

    public function execute(Content $content, ?string $parentId, ?int $position, Space $space): void
    {
        $content->getConnection()->transaction(function () use ($content, $parentId, $position, $space) {
            $content->loadMissing('block');
            $parentId = $this->contentI18nService->parentForLanguage($parentId, $content->language_iso);

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

            // Resequence siblings only when the destination is manually sorted;
            // otherwise the move is a pure reparent and the configured sort applies.
            // Decided once from the canonical destination; translations follow suit.
            $sortingEnabled = $this->contentPositionService->allowsManualSort($space, $parent);
            if ($sortingEnabled) {
                $this->contentPositionService->moveItemToPosition($space, $content, $parentId, $position);
            }

            if ($content->i18n_parent_id === null) {
                $this->moveTranslations($space, $content, $parentId, $position, $sortingEnabled);
            }

            $space->touchContentUpdatedAt();
        });
    }

    /**
     * Translations follow their canonical entry, as in a tree move, each under
     * the destination's translation in its language or the destination itself.
     */
    protected function moveTranslations(Space $space, Content $content, ?string $parentId, ?int $position, bool $sortingEnabled): void
    {
        foreach (Content::query()->where('i18n_parent_id', $content->id)->get() as $translation) {
            $translationParentId = $this->contentI18nService->parentForLanguage($parentId, $translation->language_iso);

            if ($translation->parent_id !== $translationParentId) {
                $translation->parent_id = $translationParentId;
                $translation->save();
            }

            if ($sortingEnabled) {
                $this->contentPositionService->moveItemToPosition($space, $translation, $translationParentId, $position);
            }
        }
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
