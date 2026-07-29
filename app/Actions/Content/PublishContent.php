<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Content\Serial\SerialCollisionException;
use App\Services\Search\SearchService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class PublishContent extends BasePublishAction
{
    public function __construct(
        protected SearchService $searchService,
        protected ContentSchemaValidator $contentSchemaValidator,
        protected ContentSerialAssigner $serialAssigner,
    ) {
    }

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        $this->executeWithoutIndex($data, $content, $space, $owner);
        $this->loadPublishedVersion($content);
        $this->indexContent($content, $space);
    }

    public function executeWithoutIndex(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        $content->loadMissing('block');
        $submittedContent = $this->resolveRequestedContent($data, $content);
        $contentValidation = $this->contentSchemaValidator->validateSubmission(
            $space,
            $content->block,
            $submittedContent,
            $content,
            $data['language_iso'] ?? $content->language_iso,
            $data['i18n_parent_id'] ?? $content->i18n_parent_id,
            'publish',
        );

        if (!$contentValidation->isValid()) {
            throw ValidationException::withMessages($contentValidation->errors);
        }

        // Publishing can carry edited content, so it has to reconcile edited
        // editable serials with the ledger exactly like a plain update does.
        try {
            $validatedContent = $this->serialAssigner->syncEditedValues(
                $content->block,
                $content,
                $contentValidation->content,
            );
        } catch (SerialCollisionException $exception) {
            throw ValidationException::withMessages([
                'content.'.$exception->fieldKey => [$exception->getMessage()],
            ]);
        }

        $success = false;
        $publishedAt = $this->resolvePublishedAt($data);

        $content->getConnection()->transaction(function () use ($data, $content, $space, $owner, &$success, $validatedContent, $publishedAt) {
            $this->clearScheduledVersions($content);
            $this->processPublish($data, $content, $owner, $validatedContent);
            $this->finalizePublish($content, $space, $publishedAt);
            $success = true;
        });

        if ($success) {
            $this->loadPublishedVersion($content);
        }
    }

    private function processPublish(
        array $data,
        Content $content,
        Authenticatable|User|null $owner,
        array $sanitizedContent,
    ): void {
        $payload = $this->extractDataFromRequest($data);
        $message = $payload['message'];
        $publishedAt = $payload['publishedAt'];
        $contentData = $sanitizedContent;

        $this->updateContent($data, $content);

        $values = $this->buildBaseValues($message, $owner) + [
            'published_at' => $publishedAt,
        ];

        if ($this->shouldReuseCurrentVersion($contentData, $content)) {
            $this->reuseCurrentVersionForPublish($values, $content);
        } else {
            $this->handleNewVersionPublish($values, $contentData, $content, $owner);
        }
    }

    private function finalizePublish(Content $content, Space $space, Carbon $publishedAt): void
    {
        $content->setPublishedAt($publishedAt);
        $content->save();
        $space->touch('content_updated_at');
    }

    private function handleNewVersionPublish(
        array $values,
        array $contentData,
        Content $content,
        Authenticatable|User|null $owner
    ): void {
        $version = $this->createNewVersion($values, $contentData, $content, $owner);
        $content->current_version_id = $version->id;
        $content->published_version_id = $version->id;
    }

    private function reuseCurrentVersionForPublish(array $values, Content $content): void
    {
        $content->published_version_id = $content->current_version_id;
        $this->syncCurrentVersionPublication($values, $content);
    }

    private function indexContent(Content $content, Space $space): void
    {
        $this->searchService->indexContent($content, $space);
    }
}
