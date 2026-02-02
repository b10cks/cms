<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Notifications\Management\BackupReadyNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

class CreateBackup extends QueuedJob
{
    private string $tempPath;
    private string $backupId;

    public function __construct(
        public SpaceBackup $backup,
        public Space $space
    ) {
        $this->backupId = $backup->id;
        $this->tempPath = storage_path("app/backups/{$this->backupId}");
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

            $this->sendNotifications($s3Path);

            $this->cleanup($zipPath);

        } catch (\Exception $e) {
            $this->cleanup();
            throw $e;
        }
    }

    protected function createTempDirectory(): void
    {
        File::makeDirectory($this->tempPath . '/data', 0755, true);
        File::makeDirectory($this->tempPath . '/assets', 0755, true);
    }

    protected function backupDatabase($connection): void
    {
        $this->backup->updateProgress(5);
        $config = $connection->config;
        $dumpFile = $this->tempPath . '/data/database.sql';

        $command = [
            'mysqldump',
            '--host=' . ($config['host'] ?? 'localhost'),
            '--port=' . ($config['port'] ?? 3306),
            '--user=' . ($config['username'] ?? $config['user'] ?? 'root'),
            '--password=' . ($config['password'] ?? ''),
            '--quick',
            '--lock-tables=false',
            '--skip-comments',
            '--skip-set-charset',
            '--no-tablespaces',
            $config['database'] ?? $config['dbname'] ?? $this->space->slug,
        ];

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('Database dump failed: ' . $process->getErrorOutput());
        }

        file_put_contents($dumpFile, $process->getOutput());

        $this->backup->updateProgress(10);
    }

    protected function backupAssets(): void
    {
        $storageService = app(\App\Services\Storage\StorageService::class);
        $filesystem = $storageService->getDefaultStorage($this->space);

        $allFiles = $filesystem->allFiles('/');
        $totalFiles = \count($allFiles);

        if ($totalFiles === 0) {
            $this->backup->updateProgress(100);
            return;
        }

        $processedFiles = 0;
        $assetsPath = $this->tempPath . '/assets';

        foreach ($allFiles as $file) {
            try {
                $content = $filesystem->get($file);
                $targetPath = $assetsPath . '/' . $file;

                File::makeDirectory(dirname($targetPath), 0755, true, true);
                file_put_contents($targetPath, $content);

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

    protected function sendNotifications(string $s3Path): void
    {
        $recipients = $this->backup->recipients;

        foreach ($recipients as $email) {
            try {
                $signedUrl = $this->generateSignedUrl($s3Path);

                \Illuminate\Support\Facades\Notification::route('mail', $email)
                    ->notify(new BackupReadyNotification(
                        $this->backup,
                        $this->space,
                        $signedUrl
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

    protected function generateSignedUrl(string $s3Path): string
    {
        $disk = Storage::disk('transfers');

        $expiration = now()->diffInMinutes($this->backup->expires_at);

        return $disk->temporaryUrl($s3Path, now()->addMinutes($expiration));
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

    protected function handleFailure(\Exception $e): void
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
