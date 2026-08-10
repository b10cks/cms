<?php

namespace App\Actions\Content;

use App\Exceptions\ContentVersionConflictException;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentI18nValidator;
use App\Services\Content\ContentPositionService;
use App\Services\Content\Schema\ContentSchemaValidationResult;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Content\Serial\SerialCollisionException;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class UpdateContent
{
    public function __construct(
        protected SearchService $searchService,
        protected ContentHierarchyValidator $contentHierarchyValidator,
        protected ContentI18nValidator $validator,
        protected ContentSchemaValidator $contentSchemaValidator,
        protected ContentPositionService $contentPositionService,
        protected ContentSerialAssigner $serialAssigner,
    ) {}

    protected function throwIfValidationFails(
        ContentSchemaValidationResult $contentValidation,
        bool $force = false,
    ): void {
        if (! $contentValidation->isValid()) {
            throw ValidationException::withMessages($contentValidation->errors);
        }

        if (! $force && $contentValidation->hasWarnings()) {
            throw ValidationException::withMessages($contentValidation->warnings);
        }
    }

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner)
    {
        if (! (bool) data_get($data, 'force', false)) {
            $errors = $this->validator->validate($space, $data, $content);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        $content->loadMissing('block');
        $targetParent = array_key_exists('parent_id', $data)
            ? Content::query()->with('block')->find($data['parent_id'])
            : $content->parent()->with('block')->first();
        /** @var Block $targetBlock */
        $targetBlock = array_key_exists('block_id', $data)
            ? Block::query()->findOrFail($data['block_id'])
            : $content->block;

        $this->contentHierarchyValidator->validatePlacement(
            $space,
            $targetBlock,
            $targetParent,
            $content,
            $data['language_iso'] ?? $content->language_iso,
        );

        $submission = array_key_exists('content', $data)
            ? (is_array($data['content'] ?? null) ? $data['content'] : [])
            : $content->getCurrentContent();

        $contentValidation = $this->contentSchemaValidator->validateSubmission(
            $space,
            $targetBlock,
            $submission,
            $content,
            $data['language_iso'] ?? $content->language_iso,
            $data['i18n_parent_id'] ?? $content->i18n_parent_id,
            'save',
        );

        $this->throwIfValidationFails($contentValidation, (bool) data_get($data, 'force', false));

        // Edited editable serials must move their ledger reservation with them,
        // or uniqueness would only be enforced at creation time. Readonly ones
        // were already restored from the stored entry by the validator.
        try {
            $validatedContent = $this->serialAssigner->syncEditedValues(
                $targetBlock,
                $content,
                $contentValidation->content,
            );
        } catch (SerialCollisionException $exception) {
            throw ValidationException::withMessages([
                'content.'.$exception->fieldKey => [$exception->getMessage()],
            ]);
        }

        $content->loadMissing('current_version');
        $clientParentVersionId = data_get($data, 'parent_version_id');
        if (
            $clientParentVersionId !== null
            && $clientParentVersionId !== $content->current_version_id
            && ! (bool) data_get($data, 'force_conflict', false)
        ) {
            throw new ContentVersionConflictException($content->current_version);
        }

        $indexedColumnsChanged = false;

        $content->getConnection()->transaction(function () use ($data, $content, $space, $owner, $validatedContent, $clientParentVersionId, &$indexedColumnsChanged) {
            $contentData = $validatedContent;
            $message = data_get($data, 'message');
            $sortingEnabled = $space->settings->isContentSortingEnabled();
            $shouldReposition = $sortingEnabled
                && (array_key_exists('position', $data) || array_key_exists('parent_id', $data));
            $requestedPosition = $sortingEnabled && array_key_exists('position', $data) && $data['position'] !== null
                ? (int) $data['position']
                : null;

            unset($data['content']);
            unset($data['message']);
            unset($data['force']);
            unset($data['parent_version_id']);
            unset($data['force_conflict']);
            unset($data['translations']);
            if (! $sortingEnabled) {
                // Never let a direct write change the stored position while sorting is disabled.
                unset($data['position']);
            }
            $content->fill($data);

            if ($content->current_version?->content != $contentData) {
                $version = ContentVersion::createWithContentContext([
                    'message' => $message,
                    'content_id' => $content->id,
                    'parent_id' => $clientParentVersionId ?? $content->current_version_id,
                    'content' => $contentData,
                    'created_by_id' => $owner->id,
                ], $content);
                $content->current_version_id = $version->id;
            }
            $content->save();
            // Read before repositioning: that path saves the model again and
            // replaces the recorded changes with its own.
            $indexedColumnsChanged = $content->wasChanged(['name', 'slug', 'full_slug']);

            if ($shouldReposition) {
                $this->contentPositionService->moveItemToPosition(
                    $content,
                    $content->parent_id,
                    $requestedPosition,
                );
            }

            $space->touch('content_updated_at');
        });

        // A draft save leaves `published_at` alone — the entry stays live on the
        // published version it already had, so it must not be dropped from the
        // index the way it used to be. What it can change is the row's own name
        // and slug, which the index stores alongside the published payload;
        // edits to the payload itself are staged in a draft version the
        // published-scope indexer never reads. Reindexing is a full i18n
        // resolve (plus an HTTP round trip on OpenSearch), so it is worth
        // limiting to the saves that actually move something indexed.
        if ($indexedColumnsChanged) {
            $this->searchService->indexContent($content, $space);
        }
    }
}
