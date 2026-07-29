<?php

namespace App\Actions\Content;

use App\Jobs\Content\PublishScheduledContentJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Content\Serial\SerialCollisionException;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class SchedulePublishContent extends BasePublishAction
{
    public function __construct(
        protected readonly ContentSchemaValidator $contentSchemaValidator,
        protected readonly ContentSerialAssigner $serialAssigner,
    ) {
    }

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
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

        // Same reconciliation as PublishContent: a scheduled publish can carry
        // edited content, and edited editable serials must move their ledger
        // reservation with them.
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

        $content = $this->lockContentForUpdate($content);

        $content->getConnection()->transaction(function () use ($data, $content, $space, $owner, $validatedContent) {
            $this->processSchedule($data, $content, $owner, $space, $validatedContent);
            $content->save();
        });
    }

    private function processSchedule(
        array $data,
        Content $content,
        Authenticatable|User|null $owner,
        Space $space,
        array $sanitizedContent,
    ): void {
        $scheduledAt = Carbon::parse(data_get($data, 'scheduled_at'));
        $payload = $this->extractDataFromRequest($data);
        $message = $payload['message'];
        $contentData = $sanitizedContent;

        $this->clearScheduledVersions($content);
        $this->updateContent($data, $content);

        $values = $this->buildBaseValues($message, $owner) + [
            'scheduled_at' => $scheduledAt,
        ];

        if ($this->shouldReuseCurrentVersion($contentData, $content) && $this->currentVersionIsDraft($content)) {
            $this->updateExistingVersion($values, $content);
        } else {
            $version = $this->createNewVersion($values, $contentData, $content, $owner);
            $content->current_version_id = $version->id;
        }

        if ($scheduledAt?->isFuture()) {
            PublishScheduledContentJob::dispatch(
                $space->id,
                $content->current_version_id,
            )->delay($scheduledAt);
        }
    }
}
