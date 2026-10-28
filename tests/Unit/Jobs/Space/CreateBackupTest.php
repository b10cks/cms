<?php

namespace Tests\Unit\Jobs\Space;

use App\Jobs\Space\CreateBackup;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Models\Space\AiAttachment;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class CreateBackupTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

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
        File::deleteDirectory($this->tempPath);

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

    #[Test]
    public function the_files_of_ai_attachment_rows_are_copied_under_their_object_key(): void
    {
        [$job, $space] = $this->attachmentBackupJob();
        $disk = Storage::disk('transfers');
        $sent = $this->attachment($space, 'image.png');
        $unsent = $this->attachment($space, 'notes.csv');
        $disk->put($sent->url, 'png bytes');
        $disk->put($unsent->url, 'csv bytes');
        $disk->put("ai-attachments/{$space->id}/no-row.txt", 'stray');

        $job->copyAiAttachments();

        $this->assertSame('png bytes', file_get_contents("{$this->tempPath}/transfers/{$sent->url}"));
        $this->assertSame('csv bytes', file_get_contents("{$this->tempPath}/transfers/{$unsent->url}"));
        $this->assertFileDoesNotExist("{$this->tempPath}/transfers/ai-attachments/{$space->id}/no-row.txt");
    }

    #[Test]
    public function an_unreadable_ai_attachment_fails_the_backup(): void
    {
        [$job, $space] = $this->attachmentBackupJob();
        $this->attachment($space, 'gone.png');

        $this->expectException(\RuntimeException::class);

        $job->copyAiAttachments();
    }

    #[Test]
    public function a_missing_attachment_still_fails_when_its_row_was_deleted_after_the_dump(): void
    {
        [$job, $space] = $this->attachmentBackupJob();
        $attachment = $this->attachment($space, 'gone.png');
        $job->duringDump = fn () => $attachment->delete();

        $this->expectException(\RuntimeException::class);

        $job->copyAiAttachments();
    }

    /** @return array{0: CreateBackup, 1: Space} */
    private function attachmentBackupJob(): array
    {
        Storage::fake('transfers');
        $space = Space::factory()->create();
        $this->setUpSeparateSpaceDatabase($space);
        app()->offsetUnset('currentSpace');

        $backup = new SpaceBackup;
        $backup->setRawAttributes(['id' => 'backup-1']);

        $job = new class($backup, $space) extends CreateBackup
        {
            public ?\Closure $duringDump = null;

            public function copyAiAttachments(): void
            {
                $this->backupDatabaseWithAttachments(null);
            }

            protected function backupDatabase($connection): void
            {
                ($this->duringDump)?->__invoke();
            }
        };
        (new \ReflectionProperty(CreateBackup::class, 'tempPath'))->setValue($job, $this->tempPath);

        return [$job, $space];
    }

    private function attachment(Space $space, string $name): AiAttachment
    {
        app()->offsetSet('currentSpace', $space);

        try {
            return AiAttachment::create([
                'space_id' => $space->id,
                'user_id' => (string) str()->ulid(),
                'name' => $name,
                'mime_type' => 'application/octet-stream',
                'size' => 9,
                'url' => "ai-attachments/{$space->id}/".str()->ulid().'.'.pathinfo($name, PATHINFO_EXTENSION),
            ]);
        } finally {
            app()->offsetUnset('currentSpace');
        }
    }
}
