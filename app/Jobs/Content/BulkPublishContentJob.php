<?php

namespace App\Jobs\Content;

use App\Actions\Content\PublishContent;
use App\Actions\Content\UnpublishContent;
use App\Events\Space\ContentsChanged;
use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Audit\AuditActor;
use App\Services\Content\ContentBulkPublishRun;
use App\Services\Search\SearchService;
use App\Support\SpaceContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Publishes or unpublishes a selection of entries for ContentBulkPublishController.
 * Each entry goes through the regular action on its own, so one failure does
 * not stop the run; the outcome per id lands on the ContentBulkPublishRun.
 *
 * Per-entry broadcasts are muted and replaced by one ContentsChanged, and the
 * search index is synced once per chunk. An indexing failure is reported
 * apart from publish failures, since the entry did go live.
 *
 * Runs on the `heavy` queue (--timeout=900): about 1.5 s per entry in
 * production, so 500 entries do not fit a request or the default worker.
 */
class BulkPublishContentJob extends QueuedJob
{
    /** A rerun would publish the finished entries again; the user can retry the failed ones. */
    public $tries = 1;

    public $timeout = 900;

    private const CHUNK_SIZE = 25;

    /** Lease beyond $timeout, so the lock outlives the worker killing the job. */
    private const LEASE_MARGIN_SECONDS = 60;

    /** @var list<string> */
    private array $succeeded = [];

    /** @var list<array{id: string, name: string|null, message: string}> */
    private array $failed = [];

    /** @var list<array{id: string, name: string|null, message: string}> */
    private array $indexFailed = [];

    /**
     * @param  'publish'|'unpublish'  $action
     * @param  list<string>  $ids
     */
    public function __construct(
        public Space $space,
        public User $user,
        public string $runId,
        public string $lockOwner,
        public string $action,
        public array $ids,
        public ?string $message = null,
    ) {
        $this->onQueue('heavy');
    }

    protected function execute(): void
    {
        $run = ContentBulkPublishRun::find($this->space->id, $this->runId);

        if (! $run) {
            ContentBulkPublishRun::releaseLock($this->space->id, $this->runId, $this->lockOwner);

            return;
        }

        if (! ContentBulkPublishRun::lease($this->space->id, $this->runId, $this->lockOwner, $this->timeout + self::LEASE_MARGIN_SECONDS)) {
            $run->markAsFailed('Superseded: the lock expired while this run was queued and another run started.');

            return;
        }

        $run->markAsRunning();
        $restore = SpaceContext::enter($this->space);

        try {
            $operation = $this->operation();

            Content::withoutBroadcasts(function () use ($run, $operation): void {
                $total = count($this->ids);
                $processed = 0;

                foreach (array_chunk($this->ids, self::CHUNK_SIZE) as $chunk) {
                    $this->processChunk($chunk, $operation);
                    $processed += count($chunk);
                    $run->updateProgress(
                        (int) floor($processed / max(1, $total) * 100),
                        $this->succeeded,
                        $this->failed,
                        $this->indexFailed,
                    );
                }
            });

            $run->markAsCompleted();
        } finally {
            if ($this->succeeded !== []) {
                broadcast(new ContentsChanged($this->space->id));
            }

            $restore();
            ContentBulkPublishRun::releaseLock($this->space->id, $this->runId, $this->runId);
        }
    }

    /**
     * @param  list<string>  $ids
     * @param  \Closure(Content): void  $operation
     */
    private function processChunk(array $ids, \Closure $operation): void
    {
        $contents = Content::query()->whereIn('id', $ids)->get()->keyBy('id');
        $changed = collect();

        foreach ($ids as $id) {
            $content = $contents->get($id);

            if (! $content) {
                $this->failed[] = ['id' => $id, 'name' => null, 'message' => trans('validation.exists', ['attribute' => 'id'])];

                continue;
            }

            try {
                $content->withoutAudit();
                $operation($content);
                $content->auditSpaceEvent($this->action === 'publish' ? 'published' : 'unpublished', AuditActor::user($this->user));
                $this->succeeded[] = $id;
                $changed->push($content);
            } catch (ValidationException $e) {
                $this->failed[] = ['id' => $id, 'name' => $content->name, 'message' => Arr::first(Arr::flatten($e->errors()))];
            } catch (\Throwable $e) {
                report($e);
                $this->failed[] = ['id' => $id, 'name' => $content->name, 'message' => 'Unexpected error'];
            }
        }

        $indexErrors = app(SearchService::class)->syncContents($changed, $this->space);

        foreach ($changed as $content) {
            if (isset($indexErrors[$content->id])) {
                $this->indexFailed[] = ['id' => $content->id, 'name' => $content->name, 'message' => $indexErrors[$content->id]];
            }
        }
    }

    /**
     * @return \Closure(Content): void
     */
    private function operation(): \Closure
    {
        if ($this->action === 'publish') {
            $action = app(PublishContent::class);
            $payload = $this->message === null ? [] : ['message' => $this->message];

            return fn (Content $content) => $action->executeWithoutIndex($payload, $content, $this->space, $this->user);
        }

        $action = app(UnpublishContent::class);

        return fn (Content $content) => $action->executeWithoutIndex($content, $this->space);
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Bulk content publish failed', [
            'run_id' => $this->runId,
            'space_id' => $this->space->id,
            'error' => $e->getMessage(),
        ]);

        // Also runs when the worker killed the job on timeout, where
        // execute()'s finally never ran.
        ContentBulkPublishRun::find($this->space->id, $this->runId)?->markAsFailed($e->getMessage());
        // The lease is owned by the run id; the controller's owner still holds
        // the lock if the job failed before taking it.
        ContentBulkPublishRun::releaseLock($this->space->id, $this->runId, $this->runId);
        ContentBulkPublishRun::releaseLock($this->space->id, $this->runId, $this->lockOwner);
    }

    public function tags(): array
    {
        return [
            'content-bulk-publish:'.$this->runId,
            'space:'.$this->space->id,
        ];
    }
}
