<?php

namespace Tests\Feature\Console;

use App\Console\Commands\RepairSpaceDatabasesCommand;
use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Models\Space\ContentVersion;
use App\Services\Database\SpaceDatabaseMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(RepairSpaceDatabasesCommand::class)]
class RepairSpaceDatabasesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private const DB_DIR = 'app/testing/space-repair';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::DB_DIR));

        parent::tearDown();
    }

    #[Test]
    public function a_repair_rebuilds_the_reference_ids_of_the_migrated_space(): void
    {
        $space = Space::factory()->create();
        $database = storage_path(self::DB_DIR.'/space.sqlite');
        File::ensureDirectoryExists(dirname($database));
        touch($database);
        $connection = SpaceConnection::forceCreate([
            'name' => 'internal',
            'space_id' => $space->id,
            'driver' => 'sqlite',
            'is_default' => true,
            'config' => ['database' => $database],
        ]);
        app(SpaceDatabaseMigrator::class)->migrate($connection);

        // Space models resolve the default connection under test, so that is where the indexer looks.
        $this->runSpaceMigrations();
        $this->stubManagementAuditService();
        $assetId = strtolower((string) Str::ulid());
        $version = ContentVersion::query()->forceCreate([
            'content_id' => strtolower((string) Str::ulid()),
            'content' => ['hero' => ['type' => 'asset', 'id' => $assetId]],
        ]);
        // Written around the model, so the stored ids miss the payload's asset.
        ContentVersion::query()->whereKey($version->id)->toBase()->update(['asset_ids' => '[]']);

        $this->artisan('spaces:repair-databases', ['--space' => $space->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame([$assetId], $version->fresh()->asset_ids);
    }
}
