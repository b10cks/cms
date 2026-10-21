<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Search\SearchService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class UnpublishContent
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function execute(Content $content, Space $space): void
    {
        $this->executeWithoutIndex($content, $space);

        // Already unpublished; see PublishContent::indexContent().
        try {
            $this->searchService->removeContent($content, $space);
        } catch (GuzzleException $e) {
            Log::warning('Unpublished content but could not remove it from the index', [
                'content_id' => $content->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unpublish without touching the search index, for bulk paths that sync
     * the index once per chunk.
     */
    public function executeWithoutIndex(Content $content, Space $space): void
    {
        $content->getConnection()->transaction(function () use ($content, $space) {
            $content->published_at = null;
            $content->save();

            $space->touchContentUpdatedAt();
        });
    }
}
