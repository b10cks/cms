<?php

namespace App\Console\Commands;

use App\Actions\Backup\DeleteBackup;
use App\Models\Management\SpaceBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredBackups extends Command
{
    protected $signature = 'backup:cleanup-expired {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Delete expired backups and remove files from S3';

    public function handle(DeleteBackup $deleteAction): int
    {
        $dryRun = $this->option('dry-run');
        $startTime = microtime(true);

        $this->info('Starting expired backup cleanup...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No deletions will be performed');
        }

        $expiredBackups = SpaceBackup::expired()
            ->whereIn('state', ['active', 'expired'])
            ->get();

        if ($expiredBackups->isEmpty()) {
            $this->info('No expired backups found.');
            return 0;
        }

        $this->info("Found {$expiredBackups->count()} expired backup(s) to process.");

        $deleted = 0;
        $failed = 0;

        foreach ($expiredBackups as $backup) {
            try {
                $this->info("Processing backup: {$backup->name} ({$backup->id}) for space: {$backup->space->name}");

                if (!$dryRun) {
                    $deleteAction->execute($backup);
                    $backup->markAsExpired();
                }

                $deleted++;
                $this->info("  ✓ Deleted backup: {$backup->id}");

            } catch (\Exception $e) {
                $failed++;
                $this->error("  ✗ Failed to delete backup {$backup->id}: {$e->getMessage()}");

                Log::error('Failed to delete expired backup', [
                    'backup_id' => $backup->id,
                    'space_id' => $backup->space_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $duration = microtime(true) - $startTime;

        $this->info("========================================");
        $this->info("Expired Backup Cleanup Summary");
        $this->info("========================================");
        $this->info("Total processed: {$expiredBackups->count()}");
        $this->info("Successfully deleted: {$deleted}");
        $this->info("Failed: {$failed}");
        $this->info("Duration: {$duration}s");

        if ($dryRun) {
            $this->warn('This was a dry run. No actual deletions were performed.');
        }

        Log::info('Expired backup cleanup completed', [
            'total_processed' => $expiredBackups->count(),
            'deleted' => $deleted,
            'failed' => $failed,
            'duration_seconds' => $duration,
            'dry_run' => $dryRun,
        ]);

        return $failed > 0 ? 1 : 0;
    }
}
