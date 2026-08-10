<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Database\SpaceDatabaseMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill for spaces whose database was created but never migrated (the
 * original space-creation bug). Idempotent and safe to re-run: already-migrated
 * databases are left untouched unless --force is given.
 */
class RepairSpaceDatabasesCommand extends Command
{
    protected $signature = 'spaces:repair-databases
        {--space= : Limit to a single space (id or slug)}
        {--force : Re-run the migration even if the schema already looks initialized}
        {--dry-run : Report which databases need repair without migrating}';

    protected $description = 'Detect and repair space databases that were created but never migrated';

    public function handle(SpaceDatabaseMigrator $migrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($dryRun) {
            $this->warn('DRY RUN - no migrations will run');
        }

        $query = Space::query()->with('connections');

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $checked = 0;
        $repaired = 0;
        $healthy = 0;
        $failed = 0;
        $noConnection = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($migrator, $dryRun, $force, &$checked, &$repaired, &$healthy, &$failed, &$noConnection) {
            foreach ($spaces as $space) {
                $connection = $space->connections->firstWhere('is_default', true)
                    ?? $space->connections->first();

                if (! $connection) {
                    $noConnection++;
                    $this->line("  <fg=yellow>no-conn</>   {$space->id}  (no database connection — needs full space setup)");

                    continue;
                }

                $checked++;

                try {
                    $initialized = $migrator->isInitialized($connection);

                    if ($initialized && ! $force) {
                        $healthy++;
                        continue;
                    }

                    if ($dryRun) {
                        $repaired++;
                        $reason = $initialized ? 'forced' : 'missing migrations';
                        $this->line("  <fg=cyan>repair</>    {$space->id}  ({$reason})");
                        continue;
                    }

                    $migrator->migrate($connection);

                    // A migration can rewrite delivered content (a data backfill
                    // as much as a schema change), and the delivery cache is
                    // keyed on this timestamp — without the bump the CDN keeps
                    // serving the pre-migration payload for up to a day.
                    $space->touch('content_updated_at');

                    if ($connection->state !== 'live') {
                        $connection->update(['state' => 'live']);
                    }
                    if ($space->state !== 'live') {
                        $space->update(['state' => 'live']);
                    }

                    $repaired++;
                    $this->line("  <fg=green>✓ migrated</>  {$space->id}");
                } catch (\Throwable $e) {
                    $failed++;
                    $errorMsg = $e->getMessage();
                    $shortMsg = strlen($errorMsg) > 100 ? substr($errorMsg, 0, 100) . '...' : $errorMsg;
                    $this->error("  failed     {$space->id}: {$shortMsg}");
                    Log::error('Failed to repair space database', [
                        'space' => $space->id,
                        'connection' => $connection->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        });

        $this->newLine();
        $verb = $dryRun ? 'need repair' : 'repaired';
        $this->info("Checked {$checked}; {$verb}: {$repaired}; healthy: {$healthy}; failed: {$failed}; no-connection: {$noConnection}.");

        if ($noConnection > 0) {
            $this->warn('Spaces with no connection were never fully set up — re-trigger space setup for those.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
