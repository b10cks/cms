<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class BasePublishAction
{
    abstract public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void;

    protected function extractDataFromRequest(array &$data): array
    {
        $contentData = data_get($data, 'content');
        $message = data_get($data, 'message');

        unset($data['content']);
        unset($data['message']);
        unset($data['scheduled_at']);

        return compact('contentData', 'message');
    }

    protected function updateContent(array $data, Content $content): void
    {
        $content->update($data);
        $content->load('current_version');
    }

    protected function buildBaseValues(?string $message, Authenticatable|User|null $owner): array
    {
        return [
            'message' => $message,
            'published_by_id' => $owner?->id,
        ];
    }

    protected function shouldUpdateExistingVersion(?array $contentData, Content $content): bool
    {
        return $content->current_version?->content == $contentData;
    }

    protected function updateExistingVersion(array $values, Content $content): void
    {
        ContentVersion::where('id', '=', $content->published_version_id)
            ->where('content_id', $content->id)
            ->update($values);
    }

    protected function createNewVersion(
        array $values,
        array $contentData,
        Content $content,
        Authenticatable|User|null $owner
    ): ContentVersion {
        return ContentVersion::forceCreate($values + [
            'content_id' => $content->id,
            'parent_id' => $content->current_version_id,
            'content' => $contentData,
            'created_by_id' => $owner?->id,
        ]);
    }

    protected function loadPublishedVersion(Content $content): void
    {
        $content->load('published_version');
    }
}
