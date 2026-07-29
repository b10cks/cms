<?php

namespace App\Console\Commands;

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
        $this->attemptStorageLink();

        $this->installState->write($profile);

        $this->info(sprintf(
            'b10cks setup completed with the "%s" profile.',
            $profile->value
        ));

        return self::SUCCESS;
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

    private function attemptStorageLink(): void
    {
        try {
            $exitCode = $this->call('storage:link');

            if ($exitCode !== self::SUCCESS) {
                $this->warn('storage:link did not complete successfully. Continuing without a public storage symlink.');
            }
        } catch (Throwable $exception) {
            $this->warn(sprintf(
                'storage:link could not create a symlink (%s). Continuing anyway.',
                $exception->getMessage()
            ));
        }
    }
}
