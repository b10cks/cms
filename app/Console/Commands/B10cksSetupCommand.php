<?php

namespace App\Console\Commands;

use App\Enums\InstallProfile;
use App\Services\Setup\InstallProfileResolver;
use App\Services\Setup\InstallState;
use App\Support\EditionGate;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

class B10cksSetupCommand extends Command
{
    protected $signature = 'b10cks:setup {--profile= : standard or shared}';

    protected $description = 'Install and bootstrap b10cks for the selected hosting profile';

    public function __construct(
        private readonly Filesystem $files,
        private readonly InstallProfileResolver $profileResolver,
        private readonly InstallState $installState
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $profile = $this->profileResolver->resolve($this->option('profile'));

        $this->assertProfileSupported($profile);
        $this->ensureWritableDirectories();
        $this->ensureAppKey();

        $this->callOrFail('migrate', ['--force' => true]);
        $this->callOrFail('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        if (EditionGate::isSelfHosted()) {
            $this->callOrFail('plans:setup', ['--self-hosted' => true]);
        } else {
            $this->callOrFail('plans:setup', []);
        }

        $this->callOrFail('subscriptions:backfill-free', []);
        $this->ensurePublicStorageLink();

        $this->installState->write($profile);

        $this->info(sprintf(
            'b10cks setup completed with the "%s" profile.',
            $profile->value
        ));

        return self::SUCCESS;
    }

    /**
     * The shared profile's table-prefix scheme is only wired up for
     * MySQL/MariaDB (backups dump explicit prefixed table lists via
     * mysqldump) — fail at install time, not on the first space backup.
     */
    private function assertProfileSupported(InstallProfile $profile): void
    {
        if ($profile !== InstallProfile::SHARED || config('setup.space_db_driver') === 'sqlite') {
            return;
        }

        $driver = config('database.connections.'.config('database.default').'.driver');

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException(sprintf(
                'The shared install profile does not support a "%s" main database '
                .'(space backups can only dump prefixed table lists on MySQL/MariaDB). '
                .'Set B10CKS_SPACE_DB_DRIVER=sqlite or use the standard profile.',
                $driver
            ));
        }
    }

    private function ensureWritableDirectories(): void
    {
        foreach ($this->directories() as $directory) {
            if (! $this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function directories(): array
    {
        return [
            base_path('bootstrap/cache'),
            storage_path('app'),
            storage_path('app/public'),
            storage_path('app/setup'),
            storage_path('app/spaces'),
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];
    }

    /**
     * Generate APP_KEY into .env when possible. In the Docker image /app is
     * read-only — there the entrypoint provides APP_KEY via the environment
     * before artisan ever runs, so this is a no-op.
     */
    private function ensureAppKey(): void
    {
        if (config('app.key')) {
            return;
        }

        $envPath = base_path('.env');
        $envExists = $this->files->exists($envPath);
        $canWrite = $envExists
            ? $this->files->isWritable($envPath)
            : $this->files->isWritable(dirname($envPath));

        if (! $canWrite) {
            throw new RuntimeException(
                'APP_KEY is not set and .env is not writable. Set APP_KEY in the environment '
                .'(e.g. via the Docker entrypoint) or make .env writable and rerun setup.'
            );
        }

        if (! $envExists) {
            if ($this->files->exists(base_path('.env.example'))) {
                $this->files->copy(base_path('.env.example'), $envPath);
            } else {
                $this->files->put($envPath, '');
            }
        }

        $appKey = 'base64:'.base64_encode(
            Encrypter::generateKey(config('app.cipher'))
        );

        $contents = $this->files->get($envPath);
        $line = "APP_KEY={$appKey}";

        if (preg_match('/^APP_KEY=.*/m', $contents) === 1) {
            $contents = preg_replace('/^APP_KEY=.*/m', $line, $contents) ?? $contents;
        } else {
            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        $this->files->put($envPath, $contents);
        config(['app.key' => $appKey]);
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

    /**
     * The app serves uploaded assets from public/storage -> storage/app/private
     * (NOT Laravel's storage:link default of app/public). Zip extraction and
     * FTP uploads commonly drop symlinks, so recreate it when missing.
     */
    private function ensurePublicStorageLink(): void
    {
        $link = public_path('storage');

        if (file_exists($link) || is_link($link)) {
            return;
        }

        try {
            symlink(storage_path('app/private'), $link);
        } catch (Throwable $exception) {
            $this->warn(sprintf(
                'Could not create the public/storage symlink (%s). Create it manually: public/storage -> storage/app/private.',
                $exception->getMessage()
            ));
        }
    }
}
