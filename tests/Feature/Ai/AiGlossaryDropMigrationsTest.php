<?php

namespace Tests\Feature\Ai;

use App\Models\Management\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AiGlossaryDropMigrationsTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private const SPACE_MIGRATION = 'database/migrations/spaces/0000_00_00_000029_drop_ai_glossary_storage.php';

    #[Test]
    public function the_management_drop_archives_each_glossary_with_its_concepts_and_terms_first(): void
    {
        Storage::fake('transfers');
        Carbon::setTestNow('2026-11-10 12:00:00');
        $migration = require database_path('migrations/2026_11_10_120000_drop_ai_glossaries.php');
        $migration->down();
        $space = Space::factory()->create();
        $now = now();
        DB::table('ai_glossaries')->insert(['id' => 'g1', 'scope' => 'space', 'space_id' => $space->id, 'name' => 'Shop', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('ai_glossary_concepts')->insert(['id' => 'c1', 'glossary_id' => 'g1', 'key' => 'cart', 'note' => 'Checkout only.', 'keep_exact' => false, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('ai_glossary_terms')->insert(['id' => 't1', 'concept_id' => 'c1', 'locale' => 'de', 'preferred' => 'Warenkorb', 'forbidden' => '["Korb"]', 'aliases' => '[]', 'created_at' => $now, 'updated_at' => $now]);

        $migration->up();

        $archive = json_decode(Storage::disk('transfers')->get('glossary-archive/2026-11-10-120000/g1.json'), true);
        $this->assertSame('Shop', $archive['glossary']['name']);
        $this->assertSame('Checkout only.', $archive['concepts'][0]['note']);
        $this->assertSame(['locale' => 'de', 'preferred' => 'Warenkorb', 'forbidden' => ['Korb']], array_intersect_key($archive['concepts'][0]['terms'][0], array_flip(['locale', 'preferred', 'forbidden'])));
        foreach (['ai_glossary_terms', 'ai_glossary_concepts', 'ai_glossaries'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }

    #[Test]
    public function the_space_drop_removes_suggestions_their_thread_and_the_finding_columns_under_a_table_prefix(): void
    {
        $space = Space::factory()->create();
        $this->setUpSeparateSpaceDatabase($space);
        $this->artisan('migrate:rollback', ['--database' => 'space_test', '--path' => self::SPACE_MIGRATION, '--realpath' => true]);
        $db = DB::connection('space_test');
        $this->assertTrue($db->getSchemaBuilder()->hasColumn('ai_changeset_items', 'open_findings'));
        $now = now();
        foreach (['suggestions' => 't1', 'chat' => 't2'] as $kind => $id) {
            $db->table('ai_conversations')->insert(['id' => $id, 'space_id' => $space->id, 'kind' => $kind, 'surface' => 'cmdk', 'created_at' => $now, 'updated_at' => $now]);
        }
        $db->table('ai_messages')->insert(['id' => 'm1', 'conversation_id' => 't1', 'role' => 'assistant', 'content' => 'Suggested.', 'created_at' => $now, 'updated_at' => $now]);

        $this->artisan('migrate', ['--database' => 'space_test', '--path' => self::SPACE_MIGRATION, '--realpath' => true]);

        $schema = $db->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('ai_glossary_suggestions'));
        $this->assertFalse($schema->hasColumn('ai_changeset_items', 'glossary_findings'));
        $this->assertFalse($schema->hasColumn('ai_changeset_items', 'open_findings'));
        $this->assertSame(['t2'], $db->table('ai_conversations')->pluck('id')->all());
        $this->assertSame(0, $db->table('ai_messages')->count());
    }
}
