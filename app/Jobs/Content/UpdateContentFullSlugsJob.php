<?php

namespace App\Jobs\Content;

use App\Events\Space\ContentsChanged;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use App\Services\Content\LocalizedContentSlugService;
use App\Support\SpaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateContentFullSlugsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    /**
     * A folder rename walks every descendant; the default 60 s kills it
     * halfway on large trees. Runs on the `heavy` worker (--timeout=900).
     */
    public $timeout = 600;

    public $contentId;

    public $space;

    /**
     * Create a new job instance.
     *
     * @param Content $content
     * @param Space $space
     * @return void
     */
    public function __construct(Content $content, Space $space)
    {
        $this->contentId = $content->id;
        $this->space = $space;
        $this->onQueue('heavy');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Restore the prior tenant binding so this per-space context does not
        // leak into the next job on a long-lived worker.
        $restore = SpaceContext::enter($this->space);

        try {
            $slugService = new LocalizedContentSlugService($this->space);

            $content = Content::query()->find($this->contentId);

            if (!$content) {
                Log::info("Unable to propagate full slugs because content {$this->contentId} no longer exists");

                return;
            }

            // Each descendant save would broadcast ContentUpdated, and each
            // redirect its own event; mute both and send one summary each.
            $stats = Content::withoutBroadcasts(
                fn () => Redirect::withoutBroadcasts(fn () => $slugService->processContentChildren($content, true))
            );

            if ($stats['updated'] > 0) {
                broadcast(new ContentsChanged($this->space->id))->toOthers();
                Redirect::broadcastBulkChange($this->space);
            }

            Log::info(
                "Processed children for content {$this->contentId}: {$stats['updated']} updated, {$stats['redirects_created']} redirects created, {$stats['errors']} errors"
            );
        } catch (\Exception $e) {
            Log::error("Error processing children for content {$this->contentId}: " . $e->getMessage());
            throw $e;
        } finally {
            $restore();
        }
    }
}
