<?php

namespace App\Console\Commands;

use App\Actions\Content\PublishScheduledContent as PublishAction;
use App\Models\Management\Space;
use App\Models\Space\ContentVersion;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishScheduledContent extends Command
{
    protected $signature = 'content:publish-scheduled {spaceIds?* : Optional space IDs to publish scheduled content for}';

    protected $description = 'Publish scheduled content for one or more spaces';

    protected PublishAction $publishAction;

    public function __construct(PublishAction $publishAction)
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
                $result = $this->publishScheduledContentForSpace($space);
                $totalPublished += $result['published'];
                $totalFailed += $result['failed'];
                $spaceResults[$space->id] = $result;

                if ($result['published'] > 0) {
                    $this->info("Published {$result['published']} scheduled content item(s) for space: {$space->name} ({$space->id})");
                }

                if ($result['failed'] > 0) {
                    $this->warn("Failed to publish {$result['failed']} item(s) in space: {$space->name}");
                }
            } catch (\Exception $e) {
                $this->error("Error publishing scheduled content for space {$space->name} ({$space->id}): {$e->getMessage()}");

                Log::error('Scheduled content publishing failed for space', [
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
        $this->info("Scheduled Content Publishing Summary");
        $this->info("========================================");
        $this->info("Total published: {$totalPublished}");
        $this->info("Total failed: {$totalFailed}");
        $this->info("Duration: {$duration}s");
        $this->info("Spaces processed: {$spaces->count()}");

        Log::info('Scheduled content publishing completed', [
            'total_published' => $totalPublished,
            'total_failed' => $totalFailed,
            'duration_seconds' => $duration,
            'spaces_processed' => $spaces->count(),
            'space_results' => $spaceResults,
        ]);

        return $totalFailed > 0 ? 1 : 0;
    }

    protected function publishScheduledContentForSpace(Space $space): array
    {
        $restore = SpaceContext::enter($space);

        $published = 0;
        $failed = 0;

        try {
            ContentVersion::with('contentModel')
                ->where('scheduled_at', '<=', now())
                ->whereNull('published_at')
                ->lazy(200)
                ->each(function ($version) use ($space, &$published, &$failed) {
                    try {
                        $content = $version->contentModel;

                        if (!$content) {
                            Log::warning('Scheduled content version has no associated content', [
                                'version_id' => $version->id,
                                'space_id' => $space->id,
                            ]);
                            $failed++;
                            return;
                        }

                        $this->publishAction->execute($version, $content, $space, null);
                        $published++;
                    } catch (\Exception $e) {
                        $failed++;
                        Log::error('Failed to publish scheduled content version', [
                            'version_id' => $version->id,
                            'space_id' => $space->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        $this->warn("Failed to publish version {$version->id}: {$e->getMessage()}");
                    }
                });
        } catch (\Exception $e) {
            Log::error('Error during scheduled content publishing for space', [
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
