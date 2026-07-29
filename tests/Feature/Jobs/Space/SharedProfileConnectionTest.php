<?php

namespace Tests\Feature\Jobs\Space;

use App\Jobs\Space\SetupSpace;
use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Services\Database\SpaceDatabaseMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SharedProfileConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSpace(): Space
    {
        return Space::factory()->create();
    }

    /**
     * SetupSpace subclass that records the connection instead of running the
     * full provisioning pipeline.
     */
    private function runCreateLocalDefaultConnection(Space $space): ?SpaceConnection
    {
        $job = new class($space) extends SetupSpace
        {
            public ?SpaceConnection $created = null;

            protected function dispatchSetupConnection($connection): void
            {
                $this->created = $connection;
            }

            public function exposeCreateLocalDefaultConnection(): void
            {
                $this->createLocalDefaultConnection($this->space);
            }
        };

        $job->exposeCreateLocalDefaultConnection();

        return $job->created;
    }

    #[Test]
    public function standard_profile_keeps_the_exact_legacy_attributes(): void
    {
        config(['setup.profile' => 'standard']);

        $connection = $this->runCreateLocalDefaultConnection($this->makeSpace());

        $this->assertNotNull($connection);
        $this->assertSame(config('database.connections.'.config('database.default').'.driver'), $connection->driver);
        $this->assertTrue($connection->is_default);
        // Regression guard: no config leaks into the standard path — the
        // provisioning job generates database name and credentials itself.
        $this->assertNull($connection->config);
    }

    #[Test]
    public function shared_profile_defaults_to_prefixed_main_database(): void
    {
        config(['setup.profile' => 'shared', 'setup.space_db_driver' => 'mysql']);

        $space = $this->makeSpace();
        $connection = $this->runCreateLocalDefaultConnection($space);

        $this->assertNotNull($connection);
        $this->assertSame(config('database.connections.'.config('database.default').'.driver'), $connection->driver);
        $this->assertSame(SetupSpace::sharedTablePrefix($space->id), $connection->config['prefix']);
        $this->assertSame(config('database.connections.'.config('database.default').'.database'), $connection->config['database']);
    }

    #[Test]
    public function shared_profile_with_sqlite_driver_uses_one_file_per_space(): void
    {
        config(['setup.profile' => 'shared', 'setup.space_db_driver' => 'sqlite']);

        $space = $this->makeSpace();
        $connection = $this->runCreateLocalDefaultConnection($space);

        $this->assertNotNull($connection);
        $this->assertSame('sqlite', $connection->driver);
        $this->assertSame(storage_path("app/spaces/{$space->id}/space.sqlite"), $connection->config['database']);
    }

    #[Test]
    public function shared_table_prefix_is_deterministic_and_short(): void
    {
        $prefix = SetupSpace::sharedTablePrefix('01hzx3abcdef');

        $this->assertSame($prefix, SetupSpace::sharedTablePrefix('01hzx3abcdef'));
        $this->assertSame(11, strlen($prefix));
        $this->assertMatchesRegularExpression('/^sp[0-9a-f]{8}_$/', $prefix);
    }

    #[Test]
    public function migrator_applies_the_space_schema_behind_a_table_prefix(): void
    {
        $space = $this->makeSpace();
        $prefix = SetupSpace::sharedTablePrefix($space->id);
        $database = storage_path('app/testing/shared-profile/space.sqlite');

        File::ensureDirectoryExists(dirname($database));
        File::delete($database);
        touch($database);

        $connection = SpaceConnection::forceCreate([
            'name' => 'internal',
            'space_id' => $space->id,
            'driver' => 'sqlite',
            'config' => [
                'database' => $database,
                'prefix' => $prefix,
            ],
        ]);

        app(SpaceDatabaseMigrator::class)->migrate($connection);

        config(["database.connections.assert_shared" => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => $prefix,
        ]]);

        $this->assertTrue(Schema::connection('assert_shared')->hasTable('blocks'));
        $this->assertTrue(Schema::connection('assert_shared')->hasTable('contents'));

        // The physical table names carry the prefix.
        config(["database.connections.assert_raw" => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
        ]]);
        $tables = collect(\DB::connection('assert_raw')->select("SELECT name FROM sqlite_master WHERE type = 'table'"))
            ->pluck('name');

        $this->assertTrue($tables->contains($prefix.'blocks'));
        $this->assertTrue($tables->contains($prefix.'contents'));
        $this->assertTrue($tables->contains($prefix.'migrations'));
        $this->assertFalse($tables->contains('blocks'));

        File::deleteDirectory(dirname($database));
    }
}
