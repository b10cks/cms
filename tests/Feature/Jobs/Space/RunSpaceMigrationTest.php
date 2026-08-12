<?php

namespace Tests\Feature\Jobs\Space;

use App\Jobs\Space\RunSpaceMigration;
use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Models\Management\SpaceMigration;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Database\SpaceDatabaseMigrator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the table-driven upsert path (block folders, redirects, data
 * sources/entries) end to end through the job: two real space databases, one
 * migrated into the other.
 */
class RunSpaceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const DB_DIR = 'app/testing/space-migration';

    private Space $source;

    private Space $target;

    private ConnectionInterface $src;

    private ConnectionInterface $tgt;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path(self::DB_DIR));

        [$this->source, $this->src] = $this->makeSpaceWithDatabase('source');
        [$this->target, $this->tgt] = $this->makeSpaceWithDatabase('target');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::DB_DIR));

        parent::tearDown();
    }

    private function makeSpaceWithDatabase(string $key): array
    {
        $space = Space::factory()->create();

        $database = storage_path(self::DB_DIR."/{$key}.sqlite");
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

        return [$space, app(DatabaseConnectionService::class)->getDefaultConnection($space)];
    }

    private function runMigration(string $strategy = 'overwrite', ?array $scope = null): SpaceMigration
    {
        $migration = SpaceMigration::create([
            'source_space_id' => $this->source->id,
            'target_space_id' => $this->target->id,
            'state' => 'pending',
            'progress' => 0,
            'scope' => $scope ?? ['blocks' => true, 'redirects' => true, 'data_sources' => true],
            'conflict_strategy' => $strategy,
        ]);

        (new RunSpaceMigration($migration))->handle();

        return $migration->fresh();
    }

    private function seedBlockFolder(ConnectionInterface $conn, array $attributes): string
    {
        $id = $attributes['id'] ?? (string) Str::ulid();

        $conn->table('block_folders')->insert(array_merge([
            'id' => $id,
            'external_id' => null,
            'name' => 'Folder',
            'icon' => null,
            'color' => null,
            'parent_id' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $attributes, ['id' => $id]));

        return $id;
    }

    private function seedBlock(ConnectionInterface $conn, array $attributes): string
    {
        $id = $attributes['id'] ?? (string) Str::ulid();

        $conn->table('blocks')->insert(array_merge([
            'id' => $id,
            'external_id' => null,
            'slug' => 'hero',
            'name' => 'Hero',
            'icon' => null,
            'color' => null,
            'type' => 'nestable',
            'description' => null,
            'preview_template' => null,
            'preview_file' => null,
            'schema' => null,
            'editor' => null,
            'tags' => null,
            'settings' => null,
            'folder_id' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ], $attributes, ['id' => $id]));

        return $id;
    }

    private function seedBlockTemplate(ConnectionInterface $conn, string $blockId, array $attributes): string
    {
        $id = $attributes['id'] ?? (string) Str::ulid();

        $conn->table('block_templates')->insert(array_merge([
            'id' => $id,
            'name' => 'Default',
            'icon' => null,
            'color' => null,
            'description' => null,
            'content' => '{}',
            'preview_file' => null,
            'block_id' => $blockId,
            'created_by_id' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ], $attributes, ['id' => $id, 'block_id' => $blockId]));

        return $id;
    }

    private function seedRedirect(ConnectionInterface $conn, array $attributes): string
    {
        $id = $attributes['id'] ?? (string) Str::ulid();

        $conn->table('redirects')->insert(array_merge([
            'id' => $id,
            'external_id' => null,
            'source' => '/from',
            'target' => '/to',
            'status_code' => 301,
            'hits' => 0,
            'last_used_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $attributes, ['id' => $id]));

        return $id;
    }

    #[Test]
    public function it_inserts_new_rows_under_fresh_ulids(): void
    {
        $sourceId = $this->seedBlockFolder($this->src, ['name' => 'Marketing']);
        $this->seedRedirect($this->src, ['source' => '/old', 'target' => '/new', 'status_code' => 302]);

        $migration = $this->runMigration();

        $folder = $this->tgt->table('block_folders')->first();
        $this->assertSame('Marketing', $folder->name);
        $this->assertSame($sourceId, $folder->external_id);
        $this->assertNotSame($sourceId, $folder->id);

        $redirect = $this->tgt->table('redirects')->first();
        $this->assertSame('/old', $redirect->source);
        $this->assertSame(302, (int) $redirect->status_code);

        $this->assertSame(1, $migration->result['created']['block_folders']);
        $this->assertSame(1, $migration->result['created']['redirects']);
        $this->assertSame([], $migration->result['errors']);
        $this->assertSame('completed', $migration->state);
    }

    #[Test]
    public function it_updates_the_row_matched_by_external_id(): void
    {
        $sourceId = $this->seedBlockFolder($this->src, [
            'name' => 'Renamed',
            'updated_at' => '2026-02-01 00:00:00',
        ]);
        $targetId = $this->seedBlockFolder($this->tgt, [
            'name' => 'Stale',
            'external_id' => $sourceId,
        ]);

        $migration = $this->runMigration('overwrite');

        $this->assertSame(1, $this->tgt->table('block_folders')->count());
        $this->assertSame('Renamed', $this->tgt->table('block_folders')->where('id', $targetId)->value('name'));
        $this->assertSame(1, $migration->result['updated']['block_folders']);
        $this->assertArrayNotHasKey('block_folders', $migration->result['created']);
    }

    #[Test]
    public function skip_strategy_leaves_existing_rows_untouched(): void
    {
        $sourceId = $this->seedBlockFolder($this->src, [
            'name' => 'Renamed',
            'updated_at' => '2026-02-01 00:00:00',
        ]);
        $this->seedBlockFolder($this->tgt, ['name' => 'Stale', 'external_id' => $sourceId]);

        $sourceRedirectId = $this->seedRedirect($this->src, ['source' => '/old', 'target' => '/new-target']);
        $this->seedRedirect($this->tgt, ['source' => '/old', 'target' => '/old-target', 'external_id' => $sourceRedirectId]);

        $migration = $this->runMigration('skip');

        $this->assertSame('Stale', $this->tgt->table('block_folders')->value('name'));
        $this->assertSame('/old-target', $this->tgt->table('redirects')->value('target'));
        $this->assertSame(1, $migration->result['skipped']['block_folders']);
        $this->assertSame(1, $migration->result['skipped']['redirects']);
        $this->assertArrayNotHasKey('block_folders', $migration->result['updated']);
    }

    #[Test]
    public function merge_newer_only_writes_when_the_source_row_is_newer(): void
    {
        $newerId = $this->seedBlockFolder($this->src, [
            'name' => 'Newer source',
            'updated_at' => '2026-03-01 00:00:00',
        ]);
        $olderId = $this->seedBlockFolder($this->src, [
            'name' => 'Older source',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->seedBlockFolder($this->tgt, [
            'name' => 'Target of newer',
            'external_id' => $newerId,
            'updated_at' => '2026-02-01 00:00:00',
        ]);
        $this->seedBlockFolder($this->tgt, [
            'name' => 'Target of older',
            'external_id' => $olderId,
            'updated_at' => '2026-02-01 00:00:00',
        ]);

        $migration = $this->runMigration('merge_newer');

        $this->assertSame(
            'Newer source',
            $this->tgt->table('block_folders')->where('external_id', $newerId)->value('name')
        );
        $this->assertSame(
            'Target of older',
            $this->tgt->table('block_folders')->where('external_id', $olderId)->value('name')
        );
        $this->assertSame(1, $migration->result['updated']['block_folders']);
        $this->assertSame(1, $migration->result['skipped']['block_folders']);
    }

    #[Test]
    public function merge_newer_skips_rows_with_an_identical_timestamp(): void
    {
        $sourceId = $this->seedBlockFolder($this->src, ['name' => 'Source', 'updated_at' => '2026-02-01 00:00:00']);
        $this->seedBlockFolder($this->tgt, [
            'name' => 'Target',
            'external_id' => $sourceId,
            'updated_at' => '2026-02-01 00:00:00',
        ]);

        $migration = $this->runMigration('merge_newer');

        $this->assertSame('Target', $this->tgt->table('block_folders')->value('name'));
        $this->assertSame(1, $migration->result['skipped']['block_folders']);
    }

    #[Test]
    public function it_remaps_parent_ids_onto_the_new_target_ids(): void
    {
        // Child first, so the tree ordering (not insert order) is what makes the
        // parent resolvable.
        $childId = (string) Str::ulid();
        $parentId = (string) Str::ulid();

        $this->seedBlockFolder($this->src, ['id' => $childId, 'name' => 'Child', 'parent_id' => $parentId]);
        $this->seedBlockFolder($this->src, ['id' => $parentId, 'name' => 'Parent']);
        $this->seedBlockFolder($this->src, ['name' => 'Root']);

        $this->runMigration();

        $parent = $this->tgt->table('block_folders')->where('external_id', $parentId)->first();
        $child = $this->tgt->table('block_folders')->where('external_id', $childId)->first();

        $this->assertNotNull($parent);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertNotSame($parentId, $child->parent_id);
        $this->assertNull($this->tgt->table('block_folders')->where('name', 'Root')->value('parent_id'));
    }

    #[Test]
    public function it_remaps_parent_ids_onto_rows_matched_in_the_target(): void
    {
        $parentId = $this->seedBlockFolder($this->src, ['name' => 'Parent']);
        $childId = $this->seedBlockFolder($this->src, ['name' => 'Child', 'parent_id' => $parentId]);

        $existingParentTargetId = $this->seedBlockFolder($this->tgt, [
            'name' => 'Parent',
            'external_id' => $parentId,
        ]);

        $this->runMigration('overwrite');

        $child = $this->tgt->table('block_folders')->where('external_id', $childId)->first();

        $this->assertSame($existingParentTargetId, $child->parent_id);
    }

    #[Test]
    public function it_migrates_data_sources_with_their_entries(): void
    {
        $dataSourceId = (string) Str::ulid();

        $this->src->table('data_sources')->insert([
            'id' => $dataSourceId,
            'external_id' => null,
            'name' => 'Countries',
            'slug' => 'countries',
            'description' => null,
            'dimensions' => null,
            'settings' => null,
            'is_active' => true,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->src->table('data_entries')->insert([
            'id' => (string) Str::ulid(),
            'external_id' => null,
            'data_source_id' => $dataSourceId,
            'key' => 'at',
            'value' => 'Austria',
            'dimensions' => null,
            'is_active' => true,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $migration = $this->runMigration();

        $target = $this->tgt->table('data_sources')->first();
        $entry = $this->tgt->table('data_entries')->first();

        $this->assertSame('countries', $target->slug);
        $this->assertSame($target->id, $entry->data_source_id);
        $this->assertSame('Austria', $entry->value);
        $this->assertSame(1, $migration->result['created']['data_sources']);
        $this->assertSame(1, $migration->result['created']['data_entries']);
    }

    #[Test]
    public function it_matches_data_sources_by_slug_when_the_external_id_is_unknown(): void
    {
        $this->src->table('data_sources')->insert([
            'id' => (string) Str::ulid(),
            'external_id' => null,
            'name' => 'Countries renamed',
            'slug' => 'countries',
            'description' => null,
            'dimensions' => null,
            'settings' => null,
            'is_active' => true,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-02-01 00:00:00',
        ]);

        $existingId = (string) Str::ulid();
        $this->tgt->table('data_sources')->insert([
            'id' => $existingId,
            'external_id' => null,
            'name' => 'Countries',
            'slug' => 'countries',
            'description' => null,
            'dimensions' => null,
            'settings' => null,
            'is_active' => true,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $migration = $this->runMigration('overwrite');

        $this->assertSame(1, $this->tgt->table('data_sources')->count());
        $this->assertSame('Countries renamed', $this->tgt->table('data_sources')->where('id', $existingId)->value('name'));
        $this->assertSame(1, $migration->result['updated']['data_sources']);
    }

    #[Test]
    public function it_migrates_block_templates_alongside_their_block(): void
    {
        $folderId = $this->seedBlockFolder($this->src, ['name' => 'Marketing']);
        $blockId = $this->seedBlock($this->src, ['slug' => 'hero', 'folder_id' => $folderId]);
        $this->seedBlockTemplate($this->src, $blockId, ['name' => 'Wide']);
        $this->seedBlockTemplate($this->src, $blockId, ['name' => 'Narrow']);

        $migration = $this->runMigration('overwrite', [
            'blocks' => true,
            'block_templates' => true,
        ]);

        $folder = $this->tgt->table('block_folders')->first();
        $block = $this->tgt->table('blocks')->first();

        $this->assertSame('hero', $block->slug);
        $this->assertSame($blockId, $block->external_id);
        $this->assertSame($folder->id, $block->folder_id);

        $templates = $this->tgt->table('block_templates')->orderBy('name')->get();
        $this->assertCount(2, $templates);
        $this->assertSame(['Narrow', 'Wide'], $templates->pluck('name')->all());
        $this->assertSame([$block->id, $block->id], $templates->pluck('block_id')->all());

        $this->assertSame(1, $migration->result['created']['blocks']);
        $this->assertSame(2, $migration->result['created']['block_templates']);
    }

    #[Test]
    public function block_templates_are_skipped_when_the_block_is_out_of_scope(): void
    {
        $blockId = $this->seedBlock($this->src, ['slug' => 'hero']);
        $this->seedBlockTemplate($this->src, $blockId, ['name' => 'Wide']);

        $migration = $this->runMigration('overwrite', ['blocks' => true]);

        $this->assertSame(1, $this->tgt->table('blocks')->count());
        $this->assertSame(0, $this->tgt->table('block_templates')->count());
        $this->assertArrayNotHasKey('block_templates', $migration->result['created']);
    }

    #[Test]
    public function block_templates_match_on_name_within_the_target_block(): void
    {
        $blockId = $this->seedBlock($this->src, ['slug' => 'hero']);
        $this->seedBlockTemplate($this->src, $blockId, [
            'name' => 'Wide',
            'description' => 'from source',
            'updated_at' => '2026-02-01 00:00:00',
        ]);

        $targetBlockId = $this->seedBlock($this->tgt, ['slug' => 'hero', 'external_id' => $blockId]);
        $existingTemplateId = $this->seedBlockTemplate($this->tgt, $targetBlockId, [
            'name' => 'Wide',
            'description' => 'stale',
        ]);

        $migration = $this->runMigration('overwrite', [
            'blocks' => true,
            'block_templates' => true,
        ]);

        $this->assertSame(1, $this->tgt->table('block_templates')->count());
        $this->assertSame(
            'from source',
            $this->tgt->table('block_templates')->where('id', $existingTemplateId)->value('description')
        );
        $this->assertSame(1, $migration->result['updated']['block_templates']);
    }

    #[Test]
    public function blocks_fall_back_to_matching_on_slug(): void
    {
        $this->seedBlock($this->src, [
            'slug' => 'hero',
            'name' => 'Hero renamed',
            'updated_at' => '2026-02-01 00:00:00',
        ]);
        $targetBlockId = $this->seedBlock($this->tgt, ['slug' => 'hero', 'name' => 'Hero']);

        $migration = $this->runMigration('overwrite', ['blocks' => true]);

        $this->assertSame(1, $this->tgt->table('blocks')->count());
        $this->assertSame('Hero renamed', $this->tgt->table('blocks')->where('id', $targetBlockId)->value('name'));
        $this->assertSame(1, $migration->result['updated']['blocks']);
    }

    #[Test]
    public function soft_deleted_target_blocks_are_not_matched(): void
    {
        $this->seedBlock($this->src, ['slug' => 'hero', 'name' => 'Hero']);
        $this->seedBlock($this->tgt, [
            'slug' => 'hero',
            'name' => 'Deleted hero',
            'deleted_at' => '2026-01-02 00:00:00',
        ]);

        $migration = $this->runMigration('overwrite', ['blocks' => true]);

        $this->assertSame(2, $this->tgt->table('blocks')->count());
        $this->assertSame(1, $this->tgt->table('blocks')->whereNull('deleted_at')->count());
        $this->assertSame(1, $migration->result['created']['blocks']);
    }

    #[Test]
    public function field_plugins_match_on_handle(): void
    {
        $this->seedFieldPlugin($this->src, [
            'handle' => 'color-picker',
            'name' => 'Color Picker v2',
            'updated_at' => '2026-02-01 00:00:00',
        ]);
        $existingId = $this->seedFieldPlugin($this->tgt, [
            'handle' => 'color-picker',
            'name' => 'Color Picker',
        ]);

        $migration = $this->runMigration('overwrite', ['blocks' => true]);

        $this->assertSame(1, $this->tgt->table('field_plugins')->count());
        $this->assertSame('Color Picker v2', $this->tgt->table('field_plugins')->where('id', $existingId)->value('name'));
        $this->assertSame(1, $migration->result['updated']['field_plugins']);
    }

    private function seedFieldPlugin(ConnectionInterface $conn, array $attributes): string
    {
        $id = $attributes['id'] ?? (string) Str::ulid();

        $conn->table('field_plugins')->insert(array_merge([
            'id' => $id,
            'external_id' => null,
            'name' => 'Plugin',
            'handle' => 'plugin',
            'description' => null,
            'dev_mode' => false,
            'dev_url' => null,
            'code' => null,
            'code_hash' => null,
            'code_size' => null,
            'published_at' => null,
            'manifest' => null,
            'is_active' => true,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $attributes, ['id' => $id]));

        return $id;
    }
}
