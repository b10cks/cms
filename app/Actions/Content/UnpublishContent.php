<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Search\SearchService;

class UnpublishContent
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function execute(Content $content, Space $space): void
    {
        \DB::transaction(function () use ($content, $space) {
            $content->published_at = null;
            $content->save();

            $space->touch('content_updated_at');
        });

        $this->searchService->removeContent($content, $space);
    }
}
