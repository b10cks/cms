<?php

namespace App\Actions\Content;

use App\Jobs\Content\PublishScheduledContentJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;

class SchedulePublishContent extends BasePublishAction
{
    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        $content = $this->lockContentForUpdate($content);

        \DB::transaction(function () use ($data, $content, $space, $owner) {
            $this->processSchedule($data, $content, $owner, $space);
            $content->save();
        });
    }

    private function processSchedule(array $data, Content $content, Authenticatable|User|null $owner, Space $space): void
    {
        $scheduledAt = Carbon::parse(data_get($data, 'scheduled_at'));
        ['contentData' => $contentData, 'message' => $message] = $this->extractDataFromRequest($data);

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
