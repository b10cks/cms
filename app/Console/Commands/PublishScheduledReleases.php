<?php

namespace App\Console\Commands;

use App\Actions\Release\PublishRelease;
use App\Models\Management\Space;
use App\Models\Space\Release;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishScheduledReleases extends Command
{
    protected $signature = 'releases:publish-scheduled {spaceIds?* : Optional space IDs to publish scheduled releases for}';

    protected $description = 'Publish scheduled releases for one or more spaces';

    protected PublishRelease $publishAction;

    public function __construct(PublishRelease $publishAction)
    {
        parent::__construct();
        $this->publishAction = $publishAction;
    }

    public function handle()
    {
        $startTime = microtime(true);
        $spaceIds = $this->argument('spaceIds');

        $query = Space::with('defaultConnection');
        $spaces = empty($spaceIds)
            ? $query->get()
            : $query->whereIn('id', $spaceIds)->get();

        if ($spaces->isEmpty()) {
            $this->warn('No spaces found.');
            return 0;
        }

        $totalPublished = 0;
        $totalFailed = 0;
        $spaceResults = [];

        foreach ($spaces as $space) {
            try {
                $result = $this->publishScheduledReleasesForSpace($space);
                $totalPublished += $result['published'];
                $totalFailed += $result['failed'];
                $spaceResults[$space->id] = $result;

                if ($result['published'] > 0) {
                    $this->info("Published {$result['published']} scheduled release(s) for space: {$space->name} ({$space->id})");
                }

                if ($result['failed'] > 0) {
                    $this->warn("Failed to publish {$result['failed']} release(s) in space: {$space->name}");
                }
            } catch (\Exception $e) {
                $this->error("Error publishing scheduled releases for space {$space->name} ({$space->id}): {$e->getMessage()}");

                Log::error('Scheduled releases publishing failed for space', [
                    'space_id' => $space->id,
                    'space_name' => $space->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $totalFailed++;
                $spaceResults[$space->id] = [
                    'published' => 0,
                    'failed' => 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $duration = microtime(true) - $startTime;

        $this->info("========================================");
        $this->info("Scheduled Releases Publishing Summary");
        $this->info("========================================");
        $this->info("Total published: {$totalPublished}");
        $this->info("Total failed: {$totalFailed}");
        $this->info("Duration: {$duration}s");
        $this->info("Spaces processed: {$spaces->count()}");

        Log::info('Scheduled releases publishing completed', [
            'total_published' => $totalPublished,
            'total_failed' => $totalFailed,
            'duration_seconds' => $duration,
            'spaces_processed' => $spaces->count(),
            'space_results' => $spaceResults,
        ]);

        return $totalFailed > 0 ? 1 : 0;
    }

    protected function publishScheduledReleasesForSpace(Space $space): array
    {
        $restore = SpaceContext::enter($space);

        $published = 0;
        $failed = 0;

        try {
            Release::where('published_at', null)
                ->where('publish_at', '<=', now())
                ->whereNotNull('committed_at')
                ->lazy(200)
                ->each(function ($release) use ($space, &$published, &$failed) {
                    try {
                        $this->publishAction->execute($release, $space, null);
                        $published++;
                    } catch (\Exception $e) {
                        $failed++;
                        Log::error('Failed to publish scheduled release', [
                            'release_id' => $release->id,
                            'space_id' => $space->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        $this->warn("Failed to publish release {$release->id}: {$e->getMessage()}");
                    }
                });
        } catch (\Exception $e) {
            Log::error('Error during scheduled releases publishing for space', [
                'space_id' => $space->id,
                'space_name' => $space->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            $restore();
        }

        return [
            'published' => $published,
            'failed' => $failed,
        ];
    }
}
