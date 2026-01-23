<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Database\ConnectionFactory;
use App\Services\Search\SearchService;
use Illuminate\Console\Command;

class ReindexSpaceCommand extends Command
{
    protected $signature = 'search:reindex {space_id : The ID of the space to reindex}';

    protected $description = 'Reindex all published content for a space in the search engine';

    public function __construct(
        protected SearchService $searchService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $spaceId = $this->argument('space_id');

        $space = Space::find($spaceId);
        app()->offsetSet('currentSpace', $space);

        if (!$space) {
            $this->error("Space with ID {$spaceId} not found.");
            return 1;
        }

        $this->info("Starting reindexing for space: {$space->name} ({$space->id})");
        $this->info("Search driver: {$space->getSearchDriver()->value}");

        try {
            $this->searchService->reindexSpace($space);
            $this->info('Reindexing completed successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Reindexing failed: ' . $e->getMessage());
            return 1;
        }
    }
}
