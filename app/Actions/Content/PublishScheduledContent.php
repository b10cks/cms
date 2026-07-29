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

class PublishScheduledContent
{
    public function __construct(
        protected SearchService $searchService,
        protected ContentSchemaValidator $contentSchemaValidator,
    ) {
    }

    public function execute(ContentVersion $version, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        if ($version->published_at !== null) {
            return;
        }

        $validation = $this->contentSchemaValidator->validateVersion($space, $content, $version);

        if (! $validation->isValid()) {
            throw ValidationException::withMessages($validation->errors);
        }

        $success = false;
        $content->getConnection()->transaction(function () use ($version, $content, $space, $owner, &$success) {
            $version->update([
                'published_at' => now(),
                'published_by_id' => $owner?->id,
            ]);

            $content->setPublishedAt(now());
            $content->published_version_id = $version->id;

            $content->save();
            $space->touch('content_updated_at');
            $success = true;
        });

        if ($success) {
            $this->searchService->indexContent($content, $space);
        }
    }
}
