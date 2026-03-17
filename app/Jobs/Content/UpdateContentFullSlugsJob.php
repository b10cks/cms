<?php

namespace App\Jobs\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\LocalizedContentSlugService;
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
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(LocalizedContentSlugService $slugService)
    {
        try {
            app()->offsetSet('currentSpace', $this->space);

            $content = Content::query()->find($this->contentId);

            if (!$content) {
                Log::warning("Unable to propagate full slugs because content {$this->contentId} no longer exists");

                return;
            }

            $stats = $slugService->processContentChildren($content, true);

            Log::info(
                "Processed children for content {$this->contentId}: {$stats['updated']} updated, {$stats['redirects_created']} redirects created, {$stats['errors']} errors"
            );
        } catch (\Exception $e) {
            Log::error("Error processing children for content {$this->contentId}: " . $e->getMessage());
            throw $e;
        }
    }
}
