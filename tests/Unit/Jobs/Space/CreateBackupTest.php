<?php

namespace Tests\Unit\Jobs\Space;

use App\Jobs\Space\CreateBackup;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
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
}
