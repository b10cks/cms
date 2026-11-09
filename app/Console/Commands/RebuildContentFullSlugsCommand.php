<?php

namespace App\Console\Commands;

use App\Events\Space\ContentsChanged;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use App\Services\Content\LocalizedContentSlugService;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recompose every entry's full_slug from its parent chain, adding a 301 from
 * each stale path. Repairs translated children that missed a rename of their
 * translated parent. Idempotent: rows that are already right are left alone.
 */
class RebuildContentFullSlugsCommand extends Command
{
    protected $signature = 'content:rebuild-full-slugs
        {--space= : Limit to a single space (id or slug)}
        {--dry-run : Report what would change and roll every write back}';

    protected $description = 'Recompose full slugs from the parent chain and redirect stale paths';

    public function handle(): int
    {
        $query = Space::query()->whereHas('connections');

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $dryRun = (bool) $this->option('dry-run');
        $failed = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($dryRun, &$failed): void {
            foreach ($spaces as $space) {
                $restore = SpaceContext::enter($space);

                try {
                    $stats = $this->rebuild($space, $dryRun);

                    if ($stats['updated'] > 0 && ! $dryRun) {
                        broadcast(new ContentsChanged($space->id));
                        Redirect::broadcastBulkChange($space);
                        $space->touchContentUpdatedAt();
                    }

                    $this->line("  {$space->id}  {$stats['updated']} slug(s) updated, {$stats['redirects_created']} redirect(s), {$stats['errors']} error(s)");

                    if ($stats['errors'] > 0) {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  failed  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to rebuild content full slugs', [
                        'space' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $restore();
                }
            }
        });

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{updated: int, redirects_created: int, errors: int}
     */
    private function rebuild(Space $space, bool $dryRun): array
    {
        $slugService = new LocalizedContentSlugService($space);
        // The space connection, not the default one DB::transaction() wraps.
        $connection = (new Content)->getConnection();
        $connection->beginTransaction();

        try {
            $stats = Content::withoutBroadcasts(fn () => Redirect::withoutBroadcasts(function () use ($slugService): array {
                $total = ['updated' => 0, 'redirects_created' => 0, 'errors' => 0];

                foreach (Content::query()->whereNull('parent_id')->lazyById() as $root) {
                    foreach ($slugService->processContentChildren($root) as $key => $count) {
                        $total[$key] += $count;
                    }
                }

                return $total;
            }));
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        $dryRun ? $connection->rollBack() : $connection->commit();

        return $stats;
    }
}
