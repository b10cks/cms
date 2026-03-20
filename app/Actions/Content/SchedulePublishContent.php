<?php

namespace App\Actions\Content;

use App\Jobs\Content\PublishScheduledContentJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Content\Schema\ContentSchemaValidator;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class SchedulePublishContent extends BasePublishAction
{
    public function __construct(
        protected readonly ContentSchemaValidator $contentSchemaValidator,
    ) {
    }

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        $content->loadMissing('block');
        $contentValidation = $this->contentSchemaValidator->validateSubmission(
            $space,
            $content->block,
            data_get($data, 'content', []),
            $content,
            $data['language_iso'] ?? $content->language_iso,
            $data['i18n_parent_id'] ?? $content->i18n_parent_id,
            'publish',
        );

        if (!$contentValidation->isValid()) {
            throw ValidationException::withMessages($contentValidation->errors);
        }

        $content = $this->lockContentForUpdate($content);

        \DB::transaction(function () use ($data, $content, $space, $owner, $contentValidation) {
            $this->processSchedule($data, $content, $owner, $space, $contentValidation->content);
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

        if ($this->shouldUpdateExistingVersion($contentData, $content)) {
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
