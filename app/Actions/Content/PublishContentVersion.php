<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Publishes an existing version as it is, such as rolling back to an older
 * one. Same row lock, schedule clearing and after-publish hook as a regular
 * publish; only the version comes from history instead of the request.
 */
class PublishContentVersion extends BasePublishAction
{
    public function __construct(
        protected SearchService $searchService,
        protected ContentSchemaValidator $contentSchemaValidator,
    ) {}

    /**
     * @param  array{version_id: string}  $data
     */
    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        // Scoped to the entry: another entry's version must never become this one's.
        $version = ContentVersion::query()
            ->where('content_id', $content->id)
            ->findOrFail($data['version_id']);

        $validation = $this->contentSchemaValidator->validateVersion($space, $content, $version, 'publish');

        if (! $validation->isValid()) {
            throw ValidationException::withMessages($validation->errors);
        }

        $content->getConnection()->transaction(function () use ($version, $content, $space, $owner) {
            $this->lockContentForUpdate($content);
            $this->clearScheduledVersions($content, $version);

            // A rolled-back-to version keeps when and by whom it first went live.
            ContentVersion::query()
                ->whereKey($version->id)
                ->whereNull('published_at')
                ->update(['published_at' => now(), 'published_by_id' => $owner?->id]);

            $content->published_version_id = $version->id;
            $content->setPublishedAt(now());
            $content->save();
            $space->touchContentUpdatedAt();
        }, self::TRANSACTION_ATTEMPTS);

        $this->loadPublishedVersion($content);
        self::afterPublish($content);
        $this->searchService->indexContent($content, $space);
    }
}
