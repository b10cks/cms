<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;

class PublishContent extends BasePublishAction
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        $success = false;
        \DB::transaction(function () use ($data, $content, $space, $owner, &$success) {
            $this->clearScheduledVersions($content);
            $this->processPublish($data, $content, $owner);
            $this->finalizePublish($content, $space);
            $success = true;
        });

        if ($success) {
            $this->loadPublishedVersion($content);
            $this->indexContent($content, $space);
        }
    }

    private function processPublish(array $data, Content $content, Authenticatable|User|null $owner): void
    {
        ['contentData' => $contentData, 'message' => $message] = $this->extractDataFromRequest($data);

        $this->updateContent($data, $content);

        $values = $this->buildBaseValues($message, $owner) + [
            'published_at' => now()
        ];

        if ($this->shouldUpdateExistingVersion($contentData, $content)) {
            $content->published_version_id = $content->current_version_id;
            $this->updateExistingVersion($values, $content);
        } else {
            $this->handleNewVersionPublish($values, $contentData, $content, $owner);
        }
    }

    private function finalizePublish(Content $content, Space $space): void
    {
        $content->setPublishedAt(now());
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

    private function indexContent(Content $content, Space $space): void
    {
        $this->searchService->indexContent($content, $space);
    }
}
