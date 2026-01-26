<?php

namespace App\Console\Commands;

use App\Actions\Content\PublishScheduledContent as Action;
use App\Models\Management\Space;
use App\Models\Space\ContentVersion;
use Illuminate\Console\Command;

class PublishScheduledContent extends Command
{
    protected $signature = 'content:publish-scheduled {spaceIds?* : Optional space IDs to publish scheduled content for}';

    protected $description = 'Publish scheduled content for one or more spaces';

    public function __construct(
        protected Action $publishContent
    ) {
        parent::__construct();
    }

    public function handle()
    {
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
        foreach ($spaces as $space) {
            try {
                $published = $this->publishScheduledContentForSpace($space);
                $totalPublished += $published;

                if ($published > 0) {
                    $this->info("Published {$published} scheduled content item(s) for space: {$space->name} ({$space->id})");
                }
            } catch (\Exception $e) {
                $this->error("Error publishing scheduled content for space {$space->name} ({$space->id}): {$e->getMessage()}");
            }
        }

        $this->info("Total published items: {$totalPublished}");
        return 0;
    }

    private function publishScheduledContentForSpace(Space $space): int
    {
        app()->offsetSet('currentSpace', $space);
        $scheduledVersions = ContentVersion::with('contentModel')
            ->where('scheduled_at', '<=', now())
            ->whereNull('published_at')
            ->get();

        $published = 0;
        foreach ($scheduledVersions as $version) {
            try {
                $content = $version->contentModel;
                if (!$content) {
                    continue;
                }
                $this->publishContent->execute($version, $content, $space, null);
                $published++;
            } catch (\Exception $e) {
                $this->warn("Failed to publish scheduled content version {$version->id}: {$e->getMessage()}");
            }
        }

        return $published;
    }
}
