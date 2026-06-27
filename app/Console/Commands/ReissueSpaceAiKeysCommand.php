<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Ai\PlanAiKeyResolver;
use App\Services\Ai\SpaceAiKeyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReissueSpaceAiKeysCommand extends Command
{
    protected $signature = 'ai:reissue-keys
        {--space= : Limit to a single space (id or slug)}
        {--force : Reissue keys even if they are not due this period}
        {--dry-run : Show what would change without calling OpenRouter}';

    protected $description = 'Provision, reconcile, and reissue OpenRouter keys for spaces according to their plan and subscription';

    public function handle(SpaceAiKeyProvisioner $provisioner, PlanAiKeyResolver $resolver): int
    {
        if (! config('ai.drivers.openrouter.enabled', false)) {
            $this->warn('OpenRouter is not enabled (OPENROUTER_ENABLED). Skipping.');

            return self::SUCCESS;
        }

        if (empty(config('ai.drivers.openrouter.management_key'))) {
            $this->warn('OpenRouter management key is not configured (OPENROUTER_MANAGEMENT_KEY). Skipping.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no OpenRouter calls will be made');
        }

        $query = Space::query();

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $processed = 0;
        $eligible = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($provisioner, $resolver, $force, $dryRun, &$processed, &$eligible) {
            foreach ($spaces as $space) {
                $processed++;

                try {
                    if ($dryRun) {
                        $spec = $resolver->resolve($space);
                        if ($spec->eligible) {
                            $eligible++;
                        }
                        $this->line(sprintf(
                            '  %s  %s  %s',
                            str_pad($space->id, 28),
                            $spec->eligible ? '<fg=green>eligible</>' : '<fg=gray>ineligible</>',
                            $spec->eligible
                                ? ($spec->unlimited ? 'unlimited' : '$' . number_format((float) $spec->limit, 2))
                                : '-'
                        ));

                        continue;
                    }

                    $key = $provisioner->syncForSpace($space, $force);
                    if ($key) {
                        $eligible++;
                    }
                } catch (\Throwable $e) {
                    $this->error("  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to reissue OpenRouter key for space', [
                        'space' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Processed {$processed} space(s); {$eligible} with an active key entitlement.");

        return self::SUCCESS;
    }
}
