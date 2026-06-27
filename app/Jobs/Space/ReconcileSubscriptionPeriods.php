<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Services\Subscription\SubscriptionPeriodService;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile a single space's billing-period history with its current
 * subscription state. Dispatched whenever a subscription changes (see the
 * Subscription model) and used by the periodic reconcile command.
 */
class ReconcileSubscriptionPeriods extends QueuedJob
{
    public function __construct(
        public string $spaceId,
    ) {}

    protected function execute(): void
    {
        $space = Space::find($this->spaceId);

        if (! $space) {
            return;
        }

        app(SubscriptionPeriodService::class)->reconcile($space);
    }

    protected function handleFailure(\Exception $e): void
    {
        Log::error('Failed to reconcile subscription periods', [
            'space' => $this->spaceId,
            'error' => $e->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['space:'.$this->spaceId, 'subscription-periods'];
    }
}
