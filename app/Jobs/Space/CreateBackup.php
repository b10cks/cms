<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Notifications\Management\BackupReadyNotification;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

class CreateBackup extends QueuedJob
{
    public $timeout = 1800;

    private string $tempPath;
    private string $backupId;

    public function __construct(
        public SpaceBackup $backup,
        public Space $space
    ) {
        $this->backupId = $backup->id;
        $this->tempPath = storage_path("app/backups/{$this->backupId}");
    }

    /**
     * Concurrent backups of the same space hammer the same DB and asset
     * storage for no benefit; the lock expires with the timeout so a crashed
     * worker cannot block future backups.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->space->id))
                ->releaseAfter(60)
                ->expireAfter($this->timeout),
        ];
    }

    protected function execute(): void
    {
        $this->backup->markAsStarted();

        try {
            $this->createTempDirectory();

            $connection = $this->space->defaultConnection()->first();
            if (!$connection) {
                throw new \Exception('No default database connection found for space');
            }

            $this->backupDatabase($connection);
            $this->backupAssets();

            $zipPath = $this->createZipArchive();
            $s3Path = $this->uploadToS3($zipPath);

            $fileSize = filesize($zipPath);
            $checksum = hash_file('sha256', $zipPath);

            $this->backup->markAsActive($s3Path, $fileSize, $checksum);
            $this->sendNotifications();

            $this->cleanup($zipPath);

        } catch (\Exception $e) {
            $this->cleanup();
            throw $e;
        }
    }

    protected function createTempDirectory(): void
    {
        File::makeDirectory("{$this->tempPath}/data", 0755, true);
        File::makeDirectory("{$this->tempPath}/assets", 0755, true);
    }

    protected function backupDatabase($connection): void
    {
        $this->backup->updateProgress(5);
        $config = $connection->getConnection()->getConfig();
        $dumpFile = "{$this->tempPath}/data/database.sql";

        // sqlite space databases are a single file — copy it instead of
        // shelling out to a dumper (which shared hosts may not even allow).
        if (($config['driver'] ?? null) === 'sqlite') {
            File::copy($config['database'], "{$this->tempPath}/data/database.sqlite");
            $this->backup->updateProgress(10);

            return;
        }

        if (! \function_exists('proc_open')) {
            \Log::warning('Skipping database dump: proc_open is disabled on this host', [
                'space' => $this->space->id,
            ]);
            File::put(
                "{$this->tempPath}/data/DATABASE_DUMP_SKIPPED.txt",
                "The database dump was skipped because this host does not allow spawning processes (proc_open).\n"
            );
            $this->backup->updateProgress(10);

            return;
        }

        $command = [
            config('database.dumper.command'),
            '--host=' . $config['host'],
            '--port=' . \intval($config['port']),
            '--user=' . $config['username'],
            '--password=' . $config['password'],
            ...config('database.dumper.options'),
            $config['database'],
            // Shared-profile connections live in the main database behind a
            // table prefix — restrict the dump to this space's tables, never
            // the whole database (which holds management data and every other
            // space).
            ...$this->prefixedTables($connection, $config),
        ];

        // Redirect the dump's stdout straight to the file at the OS level so the
        // (potentially very large) SQL never has to be buffered in PHP memory.
        $shellCommand = implode(' ', array_map('escapeshellarg', $command))
            . ' > ' . escapeshellarg($dumpFile);

        $process = Process::fromShellCommandline($shellCommand);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('Database dump failed: ' . $process->getErrorOutput());
        }

        $this->backup->updateProgress(10);
    }

    /**
     * @return list<string> explicit table list for the dumper; empty for
     *                      dedicated (unprefixed) space databases
     */
    protected function prefixedTables($connection, array $config): array
    {
        $prefix = data_get($connection->config, 'prefix');
        if (! $prefix) {
            return [];
        }

        if (! \in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new \Exception('Prefixed space connections can only be dumped on MySQL/MariaDB');
        }

        $pdo = $connection->getConnection()->getPdo();
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';
        $tables = array_column(
            $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($like))->fetchAll(\PDO::FETCH_NUM),
            0
        );

        if ($tables === []) {
            throw new \Exception("No tables found for prefix {$prefix}");
        }

        return $tables;
    }

    protected function backupAssets(): void
    {
        $storageService = app(\App\Services\Storage\StorageService::class);
        $filesystem = $storageService->getDefaultStorage($this->space);

        $allFiles = $filesystem->allFiles("/{$this->space->id}");
        $totalFiles = \count($allFiles);

        if ($totalFiles === 0) {
            $this->backup->updateProgress(100);
            return;
        }

        $processedFiles = 0;
        $assetsPath = "{$this->tempPath}/assets";

        foreach ($allFiles as $file) {
            try {
                $targetPath = "{$assetsPath}/{$file}";
                File::makeDirectory(dirname($targetPath), 0755, true, true);

                // Stream the source file to disk instead of loading it fully
                // into memory — assets can be large videos.
                $source = $filesystem->readStream($file);
                if ($source === null || $source === false) {
                    throw new \RuntimeException("Unable to read source file: {$file}");
                }

                $target = fopen($targetPath, 'w');
                if ($target === false) {
                    if (\is_resource($source)) {
                        fclose($source);
                    }
                    throw new \RuntimeException("Unable to open backup target: {$targetPath}");
                }

                stream_copy_to_stream($source, $target);
                fclose($target);
                if (\is_resource($source)) {
                    fclose($source);
                }

                $processedFiles++;
                $progress = 10 + (int) (($processedFiles / $totalFiles) * 90);
                $this->backup->updateProgress($progress);

            } catch (\Exception $e) {
                Log::warning("Failed to backup file: {$file}", [
                    'backup_id' => $this->backupId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function createZipArchive(): string
    {
        $zipPath = storage_path("app/backups/{$this->backupId}.zip");
        $zip = new ZipArchive();

        $flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;

        if ($this->backup->password) {
            $flags |= ZipArchive::EM_AES_256;
        }

        if ($zip->open($zipPath, $flags) !== true) {
            throw new \Exception('Failed to create zip archive');
        }

        if ($this->backup->password) {
            $zip->setPassword($this->backup->password);
        }

        $this->addDirectoryToZip($zip, $this->tempPath, 'backup');

        $zip->close();

        return $zipPath;
    }

    protected function addDirectoryToZip(ZipArchive $zip, string $path, string $zipPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $filePath = $file->getPathname();
            $relativePath = $zipPath . '/' . substr($filePath, strlen($path) + 1);

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);

                if ($this->backup->password) {
                    $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256);
                }
            }
        }
    }

    protected function uploadToS3(string $zipPath): string
    {
        $s3Key = "backups/{$this->space->id}/{$this->backupId}.zip";

        $disk = Storage::disk('transfers');
        $stream = fopen($zipPath, 'r');

        try {
            $disk->put($s3Key, $stream);
        } finally {
            fclose($stream);
        }

        return $s3Key;
    }

    protected function sendNotifications(): void
    {
        $recipients = $this->backup->recipients;

        foreach ($recipients as $email) {
            try {

                \Illuminate\Support\Facades\Notification::route('mail', $email)
                    ->notify(new BackupReadyNotification(
                        $this->backup,
                        $this->space,
                        $this->backup->getDownloadUrl()
                    ));

            } catch (\Exception $e) {
                Log::error("Failed to send backup notification", [
                    'backup_id' => $this->backupId,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function cleanup(?string $zipPath = null): void
    {
        try {
            if ($zipPath && File::exists($zipPath)) {
                File::delete($zipPath);
            }

            if (File::isDirectory($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to cleanup backup temp files", [
                'backup_id' => $this->backupId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Backup creation failed', [
            'backup_id' => $this->backupId,
            'space_id' => $this->space->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->backup->markAsFailed($e->getMessage());
    }

    public function tags(): array
    {
        return [
            'backup:' . $this->backupId,
            'space:' . $this->space->id,
            'backup-creation',
        ];
    }
}
