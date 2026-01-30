<?php

namespace App\Console\Commands;

use App\Jobs\Content\PublishScheduledContentJob;
use App\Jobs\Release\PublishScheduledReleaseJob;
use App\Models\Management\Space;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RequeueOrphanedPublishingJobs extends Command
{
    protected $signature = 'queue:requeue-orphaned-publishing {--dry-run : Display orphaned items without requeueing}';

    protected $description = 'Find and requeue orphaned scheduled content and releases that may have been missed by the queue system';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $startTime = microtime(true);

        $contentVersions = $this->findOrphanedContentVersions();
        $releases = $this->findOrphanedReleases();

        $totalOrphaned = \count($contentVersions) + \count($releases);

        if ($totalOrphaned === 0) {
            $this->info('No orphaned publishing jobs found.');
            return 0;
        }

        $this->warn("Found {$totalOrphaned} orphaned publishing job(s).");

        if ($dryRun) {
            $this->displayOrphanedItems($contentVersions, $releases);
            return 0;
        }

        $requeued = $this->requeueItems($contentVersions, $releases);

        $duration = microtime(true) - $startTime;

        $this->info("========================================");
        $this->info("Orphaned Jobs Requeue Summary");
        $this->info("========================================");
        $this->info("Total requeued: {$requeued}");
        $this->info("Duration: {$duration}s");

        Log::info('Orphaned publishing jobs requeued', [
            'total_requeued' => $requeued,
            'duration_seconds' => $duration,
        ]);

        return 0;
    }

    /**
     * Find content versions scheduled for publishing that aren't queued or already published
     *
     * @return array<int, array{space_id: string, version_id: string, space_name: string, scheduled_at: string}>
     */
    protected function findOrphanedContentVersions(): array
    {
        $orphaned = [];

        foreach (Space::query()->get() as $space) {
            app()->offsetSet('currentSpace', $space);

            $versions = ContentVersion::query()
                ->where('scheduled_at', '<=', now()->subMinutes(30))
                ->whereNull('published_at')
                ->select('id', 'scheduled_at')
                ->lazy()
                ->map(fn($version) => [
                    'space_id' => $space->id,
                    'space_name' => $space->name,
                    'version_id' => $version->id,
                    'scheduled_at' => $version->scheduled_at?->toDateTimeString(),
                ])->toArray();

            $orphaned = \array_merge($orphaned, $versions);
        }

        return $orphaned;
    }

    /**
     * Find releases scheduled for publishing that aren't queued or already published
     *
     * @return array<int, array{space_id: string, release_id: string, space_name: string, publish_at: string}>
     */
    protected function findOrphanedReleases(): array
    {
        $orphaned = [];

        foreach (Space::query()->get() as $space) {
            app()->offsetSet('currentSpace', $space);

            $releases = Release::query()
                ->where('publish_at', '<=', now()->subMinutes(30))
                ->whereNull('published_at')
                ->whereNotNull('committed_at')
                ->select('id', 'publish_at')
                ->lazy()
                ->map(
                    fn($release) => [
                        'space_id' => $space->id,
                        'space_name' => $space->name,
                        'release_id' => $release->id,
                        'publish_at' => $release->publish_at?->toDateTimeString(),
                    ]
                )->toArray();

            $orphaned = \array_merge($orphaned, $releases);
        }

        return $orphaned;
    }

    /**
     * Display orphaned items for inspection
     *
     * @param array<int, array{space_id: string, version_id: string, space_name: string, scheduled_at: string}> $contentVersions
     * @param array<int, array{space_id: string, release_id: string, space_name: string, publish_at: string}> $releases
     */
    protected function displayOrphanedItems(array $contentVersions, array $releases): void
    {
        if (!empty($contentVersions)) {
            $this->line("\n<info>Orphaned Content Versions:</info>");
            foreach ($contentVersions as $item) {
                $this->line("  - Space: {$item['space_name']} ({$item['space_id']}), Version: {$item['version_id']}, Scheduled: {$item['scheduled_at']}");
            }
        }

        if (!empty($releases)) {
            $this->line("\n<info>Orphaned Releases:</info>");
            foreach ($releases as $item) {
                $this->line("  - Space: {$item['space_name']} ({$item['space_id']}), Release: {$item['release_id']}, Publish At: {$item['publish_at']}");
            }
        }
    }

    /**
     * Requeue orphaned items
     *
     * @param array<int, array{space_id: string, version_id: string, space_name: string, scheduled_at: string}> $contentVersions
     * @param array<int, array{space_id: string, release_id: string, space_name: string, publish_at: string}> $releases
     */
    protected function requeueItems(array $contentVersions, array $releases): int
    {
        $requeued = 0;

        foreach ($contentVersions as $item) {
            try {
                PublishScheduledContentJob::dispatch(
                    $item['space_id'],
                    $item['version_id'],
                );
                $this->info("Requeued content version {$item['version_id']} from space {$item['space_name']}");
                $requeued++;
            } catch (\Exception $e) {
                $this->error("Failed to requeue content version {$item['version_id']}: {$e->getMessage()}");
                Log::error('Failed to requeue orphaned content version', [
                    'space_id' => $item['space_id'],
                    'version_id' => $item['version_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($releases as $item) {
            try {
                PublishScheduledReleaseJob::dispatch(
                    $item['space_id'],
                    $item['release_id'],
                );
                $this->info("Requeued release {$item['release_id']} from space {$item['space_name']}");
                $requeued++;
            } catch (\Exception $e) {
                $this->error("Failed to requeue release {$item['release_id']}: {$e->getMessage()}");
                Log::error('Failed to requeue orphaned release', [
                    'space_id' => $item['space_id'],
                    'release_id' => $item['release_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $requeued;
    }
}
