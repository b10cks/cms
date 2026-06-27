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

        $wasPublished = $content->published_at !== null;
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

        $content->loadMissing('current_version');
        $clientParentVersionId = data_get($data, 'parent_version_id');
        if (
            $clientParentVersionId !== null
            && $clientParentVersionId !== $content->current_version_id
            && ! (bool) data_get($data, 'force_conflict', false)
        ) {
            throw new ContentVersionConflictException($content->current_version);
        }

        \DB::transaction(function () use ($data, $content, $space, $owner, $contentValidation, $clientParentVersionId) {
            $contentData = $contentValidation->content;
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
                $content->published_at = null;
            }
            $content->save();

            if ($shouldReposition) {
                $this->contentPositionService->moveItemToPosition(
                    $content,
                    $content->parent_id,
                    $requestedPosition,
                );
            }

            $space->touch('content_updated_at');
        });

        if ($wasPublished && ($content->published_at === null)) {
            $this->searchService->removeContent($content, $space);
        }
    }
}
