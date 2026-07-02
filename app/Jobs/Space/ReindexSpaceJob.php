<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\Log;

class ReindexSpaceJob extends QueuedJob
{
    public function __construct(
        protected Space $space
    ) {
    }

    protected function execute(): void
    {
        $searchService = app(SearchService::class);
        $searchService->reindexSpace($this->space);
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Failed to reindex space', [
            'space_id' => $this->space->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleCompletion(): void
    {
        Log::info('Space reindexing completed', [
            'space_id' => $this->space->id
        ]);
    }
}
