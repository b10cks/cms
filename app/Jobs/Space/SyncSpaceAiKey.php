<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Services\Ai\SpaceAiKeyProvisioner;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile a single space's OpenRouter key with its subscription/plan.
 * Dispatched whenever a subscription changes state (see Subscription model)
 * and used by the periodic reissue command.
 */
class SyncSpaceAiKey extends QueuedJob
{
    public function __construct(
        public string $spaceId,
        public bool $forceReissue = false,
    ) {
    }

    protected function execute(): void
    {
        $space = Space::find($this->spaceId);

        if (! $space) {
            return;
        }

        app(SpaceAiKeyProvisioner::class)->syncForSpace($space, $this->forceReissue);
    }

    protected function handleFailure(\Exception $e): void
    {
        Log::error('Failed to sync space AI key', [
            'space' => $this->spaceId,
            'error' => $e->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['space:' . $this->spaceId, 'ai-key'];
    }
}
