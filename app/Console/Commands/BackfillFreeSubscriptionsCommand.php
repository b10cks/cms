<?php

namespace App\Console\Commands;

use App\Actions\Subscription\ActivateDirectSubscription;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use Illuminate\Console\Command;

/**
 * One-off backfill: grandfather legacy spaces that predate the subscription
 * feature onto the Free plan, so they are correctly gated (and keep AI) under
 * the subscription-driven key provisioning. Idempotent — only touches spaces
 * that have no subscription at all.
 */
class BackfillFreeSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:backfill-free
        {--space= : Limit to a single space (id or slug)}
        {--dry-run : Show which spaces would get a free subscription without creating any}';

    protected $description = 'Create an active Free-plan subscription for spaces that have none (legacy grandfathering)';

    public function handle(): int
    {
        $plan = Plan::query()
            ->where('is_free', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        if (! $plan) {
            $this->error('No active free plan found. Run `php artisan plans:setup` first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no subscriptions will be created');
        }

        $name = $plan->getTranslatedName() ?? 'Free';

        $query = Space::query()->whereDoesntHave('subscriptions');

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $created = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($plan, $name, $dryRun, &$created) {
            foreach ($spaces as $space) {
                if ($dryRun) {
                    $created++;
                    $this->line("  <fg=cyan>backfill</> {$space->id}");

                    continue;
                }

                // Saving fires the saved observer, which dispatches
                // SyncSpaceAiKey to provision the free-tier key.
                app(ActivateDirectSubscription::class)->execute($space->id, $plan);

                $created++;
                $this->line("  <fg=green>created</>  {$space->id}");
            }
        });

        $this->newLine();
        $this->info(($dryRun ? 'Would create' : 'Created') . " {$created} free subscription(s) using plan '{$name}'.");

        return self::SUCCESS;
    }
}
