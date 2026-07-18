<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Subscription\UsageAlertService;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hourly soft-quota sweep: measures every subscribed space against its plan
 * quotas and notifies billing viewers when the 80%/100% thresholds are crossed.
 * Sending is deduped per space/metric/threshold/month, so re-runs are cheap
 * and safe.
 */
class CheckUsageQuotasCommand extends Command
{
    protected $signature = 'usage:check-quotas
        {--space= : Limit to a single space (id or slug)}';

    protected $description = 'Check space usage against plan quotas and send soft-limit notifications';

    public function handle(UsageAlertService $alerts): int
    {
        $query = Space::query()
            ->whereHas('subscriptions', fn ($q) => $q->active())
            ->with(['subscriptions' => fn ($q) => $q->with('plan')->latest('created_at'), 'users']);

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $checked = 0;
        $sent = 0;

        $query->orderBy('id')->chunkById(50, function ($spaces) use ($alerts, &$checked, &$sent) {
            foreach ($spaces as $space) {
                // The storage metric reads the per-space DB, which resolves via
                // the ambient currentSpace binding.
                $restore = SpaceContext::enter($space);

                try {
                    $sent += $alerts->check($space);
                } catch (\Throwable $e) {
                    // One broken space (unreachable space DB, OpenRouter down)
                    // must not stall the sweep.
                    Log::warning('Usage quota check failed for space', [
                        'space_id' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $restore();
                }

                $checked++;
            }
        });

        $this->info("Checked {$checked} space(s), sent {$sent} usage alert(s).");

        return self::SUCCESS;
    }
}
