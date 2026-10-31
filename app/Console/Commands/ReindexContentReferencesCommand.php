<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Content\ContentReferenceIndexer;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild the asset, link and relation ids stored on content versions. Asset
 * usage counts, the delete guard and delivery asset resolution all read them.
 * Idempotent: rows that are already right are left alone.
 */
class ReindexContentReferencesCommand extends Command
{
    protected $signature = 'content:reindex-references
        {--space= : Limit to a single space (id or slug)}';

    protected $description = 'Rebuild asset, link and relation ids on content versions';

    public function handle(ContentReferenceIndexer $indexer): int
    {
        $query = Space::query()->whereHas('connections');

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $failed = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($indexer, &$failed): void {
            foreach ($spaces as $space) {
                $restore = SpaceContext::enter($space);

                try {
                    $changed = $indexer->reindex();

                    // Delivery resolves assets through these ids, so a repaired
                    // row changes the payload the CDN has cached.
                    if ($changed > 0) {
                        $space->touchContentUpdatedAt();
                    }

                    $this->line("  {$space->id}  {$changed} version(s) updated");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  failed  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to reindex content references', [
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
}
