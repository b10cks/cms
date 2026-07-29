<?php

namespace App\Console\Commands;

use App\Services\Setup\InstallState;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Applies the schema changes a new release brings, to the management database
 * *and* to every space database.
 *
 * Space schemas are the half that is easy to miss: `migrate` only touches the
 * management connection, while spaces carry their own migration set. Skipping
 * them leaves each space one release behind its code.
 *
 * Runs at most once per version: the installed version is recorded on the
 * storage volume, so the container entrypoint can call this on every boot and
 * it only does work after an actual image change.
 */
class B10cksUpgradeCommand extends Command
{
    protected $signature = 'b10cks:upgrade
        {--force : Run the migrations even when the recorded version already matches}';

    protected $description = 'Migrate the management and space databases after a version change';

    public function __construct(
        private readonly InstallState $installState
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->installState->exists()) {
            $this->warn('b10cks is not installed yet — run b10cks:setup first.');

            return self::SUCCESS;
        }

        $current = (string) config('app.version');
        $recorded = $this->installState->recordedVersion();

        // "latest" is the floating dev/compose default, so it cannot show that
        // the image changed. Always migrate on it rather than never.
        $unchanged = $recorded !== null && $recorded === $current && $current !== 'latest';

        if ($unchanged && ! $this->option('force')) {
            $this->line("b10cks is already at {$current} — nothing to migrate.");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Upgrading b10cks (%s -> %s)...',
            $recorded ?? 'unknown',
            $current
        ));

        $this->callOrFail('migrate', ['--force' => true]);

        // Migrates only the spaces with pending migrations: the repair command
        // treats a space with unapplied space migrations as uninitialized.
        // Deliberately without --force, which would re-run every space.
        $this->callOrFail('spaces:repair-databases', []);

        $this->installState->recordVersion($current);

        $this->info("b10cks upgraded to {$current}.");

        return self::SUCCESS;
    }

    private function callOrFail(string $command, array $arguments): void
    {
        $exitCode = $this->call($command, $arguments);

        if ($exitCode !== self::SUCCESS) {
            throw new RuntimeException(sprintf(
                'The "%s" command failed with exit code %d.',
                $command,
                $exitCode
            ));
        }
    }
}
