<?php

namespace App\Console\Commands;

use App\Events\Space\ContentsChanged;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\Redirect;
use App\Services\Content\ContentI18nService;
use App\Services\Content\LocalizedContentSlugService;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Bring a space's content tree back to its invariants. Translations filed under
 * the canonical parent move under the parent's translation, then every
 * full_slug is recomposed from its parent chain, with a 301 from each stale
 * path. Idempotent: rows that are already right are left alone.
 */
class RepairContentTreeCommand extends Command
{
    protected $signature = 'content:repair-tree
        {--space= : Limit to a single space (id or slug)}
        {--dry-run : Report what would change and roll every write back}';

    protected $description = 'Re-home translated children and recompose full slugs, redirecting stale paths';

    public function handle(ContentI18nService $i18n): int
    {
        $query = Space::query()->whereHas('connections');

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $dryRun = (bool) $this->option('dry-run');
        $failed = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($i18n, $dryRun, &$failed): void {
            foreach ($spaces as $space) {
                $restore = SpaceContext::enter($space);

                try {
                    $stats = $this->repair($space, $i18n, $dryRun);

                    if ($stats['rehomed'] + $stats['updated'] > 0 && ! $dryRun) {
                        broadcast(new ContentsChanged($space->id));
                        Redirect::broadcastBulkChange($space);
                        $space->touchContentUpdatedAt();
                    }

                    $this->line("  {$space->id}  {$stats['rehomed']} re-homed, {$stats['updated']} slug(s) updated, {$stats['redirects_created']} redirect(s), {$stats['errors']} error(s)");

                    if ($stats['errors'] > 0) {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  failed  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to repair the content tree', [
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
     * @return array{rehomed: int, updated: int, redirects_created: int, errors: int}
     */
    private function repair(Space $space, ContentI18nService $i18n, bool $dryRun): array
    {
        $slugService = new LocalizedContentSlugService($space);
        // The space connection, not the default one DB::transaction() wraps.
        $connection = (new Content)->getConnection();
        $connection->beginTransaction();

        try {
            $stats = Content::withoutBroadcasts(fn () => Redirect::withoutBroadcasts(function () use ($slugService, $i18n): array {
                $total = ['rehomed' => 0, 'updated' => 0, 'redirects_created' => 0, 'errors' => 0];

                // Only translations whose canonical entry has translated children.
                $adopters = Content::query()
                    ->whereNotNull('i18n_parent_id')
                    ->whereIn('i18n_parent_id', Content::query()->whereNotNull('i18n_parent_id')->select('parent_id'));

                foreach ($adopters->lazyById() as $translation) {
                    $total['rehomed'] += $i18n->adoptTranslatedChildren($translation);
                }

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
