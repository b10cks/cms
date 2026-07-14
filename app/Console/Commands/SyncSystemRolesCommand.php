<?php

namespace App\Console\Commands;

use App\Services\Auth\SystemRoleSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSystemRolesCommand extends Command
{
    protected $signature = 'authorization:sync-roles
        {--prune : Remove system roles that no longer exist in config}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Reconcile the system roles in the database with config/authorization.php';

    public function handle(SystemRoleSynchronizer $synchronizer): int
    {
        if (! DB::getSchemaBuilder()->hasTable('roles')) {
            $this->error('The roles table does not exist. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $results = $synchronizer->sync((bool) $this->option('prune'), $dryRun);

        $changed = array_values(array_filter(
            $results,
            fn (array $result) => $result['status'] !== SystemRoleSynchronizer::UNCHANGED,
        ));

        if ($changed === []) {
            $this->info('System roles are already up to date.');

            return self::SUCCESS;
        }

        $this->table(
            ['Scope', 'Role', 'Status', 'Changed'],
            array_map(fn (array $result) => [
                $result['scope'],
                $result['key'],
                $this->formatStatus($result['status']),
                implode(', ', $result['changes']) ?: '-',
            ], $changed),
        );

        $inUse = array_filter(
            $changed,
            fn (array $result) => $result['status'] === SystemRoleSynchronizer::IN_USE,
        );

        if ($inUse !== []) {
            $this->warn(sprintf(
                '%d role(s) were dropped from config but are still assigned. Reassign their members and invites, then re-run with --prune.',
                count($inUse),
            ));
        }

        $this->newLine();
        $this->info($dryRun
            ? count($changed).' role(s) would change. Re-run without --dry-run to apply.'
            : count($changed).' role(s) synced.');

        return self::SUCCESS;
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            SystemRoleSynchronizer::CREATED => '<fg=green>created</>',
            SystemRoleSynchronizer::UPDATED => '<fg=yellow>updated</>',
            SystemRoleSynchronizer::RESTORED => '<fg=yellow>restored</>',
            SystemRoleSynchronizer::PRUNED => '<fg=red>pruned</>',
            SystemRoleSynchronizer::IN_USE => '<fg=red>in use</>',
            default => $status,
        };
    }
}
