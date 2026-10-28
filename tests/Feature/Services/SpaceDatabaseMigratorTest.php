<?php

namespace Tests\Feature\Services;

use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Services\Database\SpaceDatabaseMigrator;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaceDatabaseMigratorTest extends TestCase
{
    use RefreshDatabase;

    private const AI_MIGRATION = '0000_00_00_000018_create_ai_tables';

    #[Test]
    public function a_new_migration_that_failed_stays_pending_until_its_tables_exist(): void
    {
        $database = storage_path('app/testing/space-migrator/space.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::delete($database);
        touch($database);
        config(['database.connections.space_file' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']]);

        $connection = SpaceConnection::forceCreate([
            'name' => 'internal',
            'space_id' => Space::factory()->create()->id,
            'driver' => 'sqlite',
            'config' => ['database' => $database],
        ]);
        $migrator = app(SpaceDatabaseMigrator::class);
        $migrator->migrate($connection);

        // An existing space before the AI migration shipped, where creating
        // its tables fails part way.
        $space = Schema::connection('space_file');
        foreach (['ai_glossary_suggestions', 'ai_changeset_items', 'ai_changesets', 'ai_attachments', 'ai_messages', 'ai_run_events', 'ai_runs', 'ai_conversations'] as $table) {
            $space->drop($table);
        }
        DB::connection('space_file')->table('migrations')->where('migration', self::AI_MIGRATION)->delete();
        $space->create('ai_runs', fn (Blueprint $table) => $table->id());

        try {
            $migrator->migrate($connection);
            $this->fail('The migration should have failed on the existing table.');
        } catch (QueryException) {
        }

        $this->assertFalse($migrator->isInitialized($connection));

        // Clear what the failed attempt left, as a person fixing it would.
        $space->drop('ai_runs');
        $space->dropIfExists('ai_conversations');
        $migrator->migrate($connection);

        $this->assertTrue($migrator->isInitialized($connection));
        $this->assertTrue($space->hasTable('ai_glossary_suggestions'));
        $this->assertSame([self::AI_MIGRATION], DB::connection('space_file')->table('migrations')->where('migration', 'like', '%create_ai_tables')->pluck('migration')->all());

        DB::purge('space_file');
        File::deleteDirectory(dirname($database));
    }
}
