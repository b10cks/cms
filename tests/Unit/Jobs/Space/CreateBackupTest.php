<?php

namespace Tests\Unit\Jobs\Space;

use App\Jobs\Space\CreateBackup;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateBackupTest extends TestCase
{
    private string $tempPath;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/backup-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempPath, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            @unlink($path);
        }
        @rmdir($this->tempPath.'/assets');
        @rmdir($this->tempPath);

        parent::tearDown();
    }

    #[Test]
    public function database_credentials_are_written_to_a_protected_option_file(): void
    {
        $backup = new SpaceBackup;
        $backup->setRawAttributes(['id' => 'backup-1']);

        $space = new Space;
        $space->setRawAttributes(['id' => 'space-1']);

        $job = new class($backup, $space) extends CreateBackup
        {
            public function createCredentialsFile(array $config): string
            {
                return $this->createDatabaseCredentialsFile($config);
            }
        };

        $property = new \ReflectionProperty(CreateBackup::class, 'tempPath');
        $property->setValue($job, $this->tempPath);

        $path = $job->createCredentialsFile([
            'host' => 'db.internal',
            'port' => 3306,
            'username' => 'backup-user',
            'password' => "quote\" slash\\ newline\n",
        ]);
        $this->files[] = $path;

        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(<<<'OPTIONS'
        [client]
        host="db.internal"
        port="3306"
        user="backup-user"
        password="quote\" slash\\ newline\n"

        OPTIONS, file_get_contents($path));
    }

    #[Test]
    public function an_asset_copy_failure_fails_the_backup(): void
    {
        $backup = new SpaceBackup;
        $backup->setRawAttributes(['id' => 'backup-1']);
        $space = new Space;
        $space->setRawAttributes(['id' => 'space-1']);

        $filesystem = \Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('allFiles')->once()->andReturn(['space-1/missing.jpg']);
        $filesystem->shouldReceive('readStream')->once()->andThrow(new \RuntimeException('copy failed'));

        $storage = \Mockery::mock(StorageService::class);
        $storage->shouldReceive('getDefaultStorage')->once()->with($space)->andReturn($filesystem);
        app()->instance(StorageService::class, $storage);

        $job = new class($backup, $space) extends CreateBackup
        {
            public function copyAssets(): void
            {
                $this->backupAssets();
            }
        };

        $property = new \ReflectionProperty(CreateBackup::class, 'tempPath');
        $property->setValue($job, $this->tempPath);
        mkdir($this->tempPath.'/assets');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('copy failed');

        $job->copyAssets();
    }
}
