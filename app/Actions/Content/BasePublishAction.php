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

    protected function resolveRequestedContent(array $data, Content $content): array
    {
        if (! array_key_exists('content', $data)) {
            return $content->getCurrentContent();
        }

        return is_array($data['content'] ?? null) ? $data['content'] : [];
    }

    protected function buildBaseValues(?string $message, Authenticatable|User|null $owner): array
    {
        return [
            'message' => $message,
            'published_by_id' => $owner?->id,
        ];
    }

    protected function shouldReuseCurrentVersion(array $contentData, Content $content): bool
    {
        $content->loadMissing('current_version');

        return $content->current_version?->content == $contentData;
    }

    protected function currentVersionIsDraft(Content $content): bool
    {
        $content->loadMissing('current_version');

        return $content->current_version?->published_at === null;
    }

    protected function updateExistingVersion(array $values, Content $content): void
    {
        $updated = ContentVersion::where('id', '=', $content->current_version_id)
            ->where('content_id', $content->id)
            ->whereNull('published_at')
            ->update($values);

        if ($updated === 0) {
            throw new \Exception(
                'Cannot update version: it has already been published or does not exist.'
            );
        }
    }

    protected function createNewVersion(
        array $values,
        array $contentData,
        Content $content,
        Authenticatable|User|null $owner
    ): ContentVersion {
        return ContentVersion::createWithContentContext($values + [
            'content_id' => $content->id,
            'parent_id' => $content->current_version_id,
            'content' => $contentData,
            'created_by_id' => $owner?->id,
        ], $content);
    }

    protected function loadPublishedVersion(Content $content): void
    {
        $content->load('published_version');
    }

    protected function lockContentForUpdate(Content $content): Content
    {
        return Content::lockForUpdate()->findOrFail($content->id);
    }

    protected function clearScheduledVersions(Content $content, ?ContentVersion $exceptVersion = null): void
    {
        $query = ContentVersion::where('content_id', $content->id)
            ->whereNotNull('scheduled_at')
            ->whereNull('published_at');

        if ($exceptVersion) {
            $query->where('id', '!=', $exceptVersion->id);
        }

        $query->update(['scheduled_at' => null]);
    }

    protected function touchSpace(Space $space, string $column = 'content_updated_at'): void
    {
        try {
            $space->touch($column);
        } catch (\Exception $e) {
            \Log::error('Failed to touch space', [
                'space_id' => $space->id,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
