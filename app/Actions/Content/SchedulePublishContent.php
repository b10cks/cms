<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;

class SchedulePublishContent extends BasePublishAction
{
    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        \DB::transaction(function () use ($data, $content, $space, $owner) {
            $this->processSchedule($data, $content, $owner);
            $content->save();
        });
    }

    private function processSchedule(array $data, Content $content, Authenticatable|User|null $owner): void
    {
        $scheduledAt = Carbon::parse(data_get($data, 'scheduled_at'));
        ['contentData' => $contentData, 'message' => $message] = $this->extractDataFromRequest($data);

        ContentVersion::where('content_id', $content->id)
            ->whereNotNull('scheduled_at')
            ->update(['scheduled_at' => null]);

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
    }
}
