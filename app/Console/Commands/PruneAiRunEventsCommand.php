<?php

namespace App\Console\Commands;

use App\Enums\AiRunState;
use App\Models\Management\Space;
use App\Models\Space\AiRun;
use App\Models\Space\AiRunEvent;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the stored stream events of runs that finished more than the
 * configured days ago, space by space. Events only serve live replay and
 * catch-up; runs, transcripts, messages and changesets stay.
 */
class PruneAiRunEventsCommand extends Command
{
    private const CHUNK = 200;

    protected $signature = 'ai:prune-run-events';

    protected $description = 'Delete stream events of AI runs that finished long ago';

    public function handle(): int
    {
        $pruned = 0;
        $failed = 0;

        Space::query()->with('defaultConnection')->orderBy('id')->chunkById(100, function ($spaces) use (&$pruned, &$failed) {
            foreach ($spaces as $space) {
                $restore = SpaceContext::enter($space);

                try {
                    $pruned += $this->pruneCurrentSpace();
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Failed to prune AI run events of a space', ['space' => $space->id, 'error' => $e->getMessage()]);
                } finally {
                    $restore();
                }
            }
        });

        $this->info("{$pruned} run event(s) pruned; {$failed} space(s) failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pruneCurrentSpace(): int
    {
        $pruned = 0;

        AiRun::query()
            ->select('id')
            ->whereIn('state', [AiRunState::Completed, AiRunState::Failed, AiRunState::Cancelled])
            ->where('finished_at', '<', now()->subDays((int) config('ai.runs.event_retention_days')))
            ->whereHas('events')
            ->chunkById(self::CHUNK, function ($runs) use (&$pruned) {
                $pruned += AiRunEvent::query()->whereIn('run_id', $runs->modelKeys())->delete();
            });

        return $pruned;
    }
}
