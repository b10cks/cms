<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;

use App\Actions\Subscription\SyncSubscriptionFromLemonSqueezy;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncLemonSqueezySubscriptions extends Command
{
    protected $signature = 'subscriptions:sync-lemonsqueezy {--dry-run : Show what would be synced without saving}';

    protected $description = 'Reconcile subscription state with LemonSqueezy';

    public function handle(LemonSqueezyService $ls, SyncSubscriptionFromLemonSqueezy $sync): int
    {
        if (! $ls->isConfigured()) {
            $this->warn('LemonSqueezy is not configured (missing LEMONSQUEEZY_API_KEY or LEMONSQUEEZY_STORE_ID). Skipping.');

            return 0;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be saved');
        }

        $this->info('Syncing subscriptions with LemonSqueezy...');
        $startTime = microtime(true);

        try {
            if (! $dryRun) {
                $synced = $sync->syncAll();
                $this->info("Synced {$synced} subscription(s).");
            } else {
                $lsSubscriptions = $ls->listSubscriptions();
                $this->info('Found '.\count($lsSubscriptions).' subscription(s) at LemonSqueezy.');

                $rows = [];
                foreach ($lsSubscriptions as $lsSub) {
                    $normalized = $ls->normalizeSubscription($lsSub);
                    $lsId = $normalized['lemon_squeezy_id'];
                    $lsStatus = $normalized['status'];

                    $local = \App\Models\Management\Subscription::where('lemon_squeezy_id', $lsId)->first();
                    $spaceId = data_get($lsSub, 'attributes.custom_data.space_id');
                    $pendingMatch = ! $local && $spaceId
                        ? \App\Models\Management\Subscription::where('space_id', $spaceId)
                            ->where('status', SubscriptionStatus::Pending->value)
                            ->whereNull('lemon_squeezy_id')
                            ->first()
                        : null;

                    $localStatus = match (true) {
                        $local !== null => "linked ({$local->status} → {$lsStatus})",
                        $pendingMatch !== null => "pending match → {$lsStatus}",
                        default => 'no local record (would create)',
                    };

                    $rows[] = [$lsId, $lsStatus, $localStatus];
                }

                if ($rows) {
                    $this->table(['LS ID', 'LS Status', 'Local Action'], $rows);
                }
            }
        } catch (\Throwable $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            Log::error('LemonSqueezy subscription sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->info("Done in {$duration}s.");

        return 0;
    }
}
