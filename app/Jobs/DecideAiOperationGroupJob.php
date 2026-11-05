<?php

namespace App\Jobs;

use App\Actions\Ai\DecidableItems;
use App\Models\Management\Space;
use App\Models\Space\AiOperation;
use App\Models\User;
use App\Services\Ai\Operations\OperationReview;
use App\Services\Ai\Threads\AiThreads;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Accepts or rejects a large review group of an operation in bounded slices,
 * re-dispatching itself until the group is decided. Each slice recomputes
 * the group, and the editor's rights are checked again on every slice.
 *
 * The cache lock only keeps slices of one operation from taking turns on the
 * same items. It can expire or be evicted, and HTTP decisions do not take it:
 * what keeps an item from being decided twice is the row lock of each
 * decision, see {@see DecidableItems}. A worker lost mid-slice is not retried:
 * committed items stay decided, and the editor repeats the group decision for
 * the rest, so no group is accepted long after the editor last looked at it.
 */
class DecideAiOperationGroupJob extends QueuedJob
{
    public $tries = 1;

    public $timeout = 300;

    /** Wall time of one slice, below the queue timeout. */
    private const SLICE_SECONDS = 45;

    public function __construct(
        public string $spaceId,
        public string $operationId,
        public string $userId,
        public string $group,
        public bool $accept,
    ) {}

    protected function execute(): void
    {
        $space = Space::query()->find($this->spaceId);
        $user = User::query()->find($this->userId);
        if (! $space || ! $user) {
            OperationReview::clearQueued($this->operationId, $this->group);

            return;
        }

        $restore = SpaceContext::enter($space);
        $lock = Cache::lock("ai-operation-review:{$this->operationId}", self::SLICE_SECONDS + 30);

        if (! $lock->get()) {
            $restore();
            // Another slice of this operation is deciding; follow it.
            self::dispatch(...$this->arguments())->delay(now()->addSeconds(10));

            return;
        }

        try {
            $operation = AiOperation::query()->find($this->operationId);
            $review = app(OperationReview::class);
            $deadline = microtime(true) + self::SLICE_SECONDS;
            $remaining = match (true) {
                $operation === null => 0,
                $this->accept => $review->acceptSlice($operation, $space, $user, $this->group, $deadline)['remaining'],
                default => $review->rejectSlice($operation, $space, $user, $this->group, $deadline)['remaining'],
            };
        } finally {
            $lock->release();
            $restore();
        }

        // After the release: a sync queue runs the next slice right here.
        if ($remaining > 0 && $operation !== null) {
            OperationReview::markQueued($operation, $this->group, $this->accept);
            self::dispatch(...$this->arguments());

            return;
        }

        OperationReview::clearQueued($this->operationId, $this->group);
        if ($operation !== null) {
            // Clients refetch on this and find the group no longer queued.
            app(AiThreads::class)->changed($operation->space_id, $operation->conversation_id, activity: false);
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        OperationReview::clearQueued($this->operationId, $this->group);
        Log::error('Deciding an AI operation review group failed', ['operation' => $this->operationId, 'group' => $this->group, 'exception' => $e]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: bool} */
    private function arguments(): array
    {
        return [$this->spaceId, $this->operationId, $this->userId, $this->group, $this->accept];
    }
}
