<?php

namespace App\Console\Commands;

use App\Enums\SearchDriver;
use App\Jobs\Space\ReindexSpaceJob;
use App\Models\Management\Space;
use App\Services\Search\SearchService;
use Illuminate\Console\Command;

class SwitchSearchDriverCommand extends Command
{
    protected $signature = 'search:driver {space_id : The ID of the space} {driver : The search driver (mysql or opensearch)}';

    protected $description = 'Switch the search driver for a space and trigger reindexing';

    public function __construct(
        protected SearchService $searchService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $spaceId = $this->argument('space_id');
        $driverValue = $this->argument('driver');

        $space = Space::find($spaceId);
        app()->offsetSet('currentSpace', $space);

        if (!$space) {
            $this->error("Space with ID {$spaceId} not found.");
            return 1;
        }

        try {
            $newDriver = SearchDriver::from($driverValue);
        } catch (\ValueError $e) {
            $this->error("Invalid driver: {$driverValue}");
            $this->info("Valid drivers: mysql, opensearch");
            return 1;
        }

        $oldDriver = $space->getSearchDriver();

        if ($oldDriver === $newDriver) {
            $this->info("Space '{$space->name}' is already using the {$newDriver->value} driver.");
            return 0;
        }

        $this->info("Switching search driver for space: {$space->name} ({$space->id})");
        $this->info("Current driver: {$oldDriver->value}");
        $this->info("New driver: {$newDriver->value}");

        if (!$this->confirm('Do you want to continue?', true)) {
            $this->info('Operation cancelled.');
            return 0;
        }

        try {
            $this->searchService->switchDriver($space, $newDriver);
            $this->info("✓ Search driver switched successfully to {$newDriver->value}");

            $this->info("Starting reindexing in background...");
            ReindexSpaceJob::dispatch($space);
            $this->info("✓ Reindexing job dispatched");

            $this->newLine();
            $this->info("Search driver switched successfully!");
            $this->info("Reindexing is running in the background.");
            $this->info("Monitor the queue worker logs for progress.");

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to switch search driver: {$e->getMessage()}");
            return 1;
        }
    }
}
