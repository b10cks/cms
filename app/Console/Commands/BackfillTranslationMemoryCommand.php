<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Ai\Translation\TranslationMemory;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Fills the translation memory from the published versions of existing
 * translations, the same way a publish does. Safe to run again.
 */
class BackfillTranslationMemoryCommand extends Command
{
    protected $signature = 'ai:backfill-translation-memory {space_id? : Only this space}';

    protected $description = 'Store translation units for every published translation';

    public function handle(TranslationMemory $memory): int
    {
        $spaceId = $this->argument('space_id');
        $spaces = Space::query()->when($spaceId, fn ($query) => $query->whereKey($spaceId))->get();

        if ($spaceId && $spaces->isEmpty()) {
            $this->error(sprintf('Space %s not found.', $spaceId));

            return self::FAILURE;
        }

        foreach ($spaces as $space) {
            $restore = SpaceContext::enter($space);
            $units = 0;
            $entries = 0;

            try {
                Content::query()
                    ->whereNotNull('i18n_parent_id')
                    ->whereNotNull('published_version_id')
                    ->select(['id', 'i18n_parent_id', 'language_iso', 'published_version_id'])
                    ->chunkById(100, function ($translations) use ($memory, &$units, &$entries) {
                        foreach ($translations as $translation) {
                            $units += $memory->record($translation);
                            $entries++;
                        }
                    });
            } catch (\Throwable $e) {
                $this->error("{$space->id}: {$e->getMessage()}");

                continue;
            } finally {
                $restore();
            }

            $this->info("{$space->id}: {$units} units from {$entries} translations.");
        }

        return self::SUCCESS;
    }
}
