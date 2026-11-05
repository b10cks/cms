<?php

namespace Tests\Feature\Ai;

use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\Space;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\PolicySnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AiGlossaryNormalizeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Puts the tables back as they were before the migration, with rows in the old shape. */
    private function oldTables(string $glossaryId, string $conceptId): void
    {
        Schema::drop('ai_glossary_terms');
        Schema::drop('ai_glossary_terms_legacy');
        Schema::create('ai_glossary_terms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('glossary_id')->constrained('ai_glossaries')->cascadeOnDelete();
            $table->string('source_locale', 20);
            $table->string('term');
            $table->longText('translations')->nullable();
            $table->boolean('case_sensitive')->default(false);
            $table->boolean('do_not_translate')->default(false);
            $table->longText('forbidden')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->foreignUlid('concept_id')->nullable()->constrained('ai_glossary_concepts')->nullOnDelete();
            $table->longText('source_aliases')->nullable();
        });

        $row = static fn (string $id, array $fields) => [
            'id' => $id, 'glossary_id' => $glossaryId, 'translations' => null, 'forbidden' => null, 'source_aliases' => null,
            'note' => null, 'concept_id' => null, 'do_not_translate' => false, 'case_sensitive' => false, ...$fields,
        ];

        DB::table('ai_glossary_terms')->insert([
            $row('01J00000000000000000000001', ['source_locale' => 'en', 'term' => 'cart', 'translations' => '{"de":"Warenkorb"}', 'forbidden' => '["basket"]', 'note' => 'Shop', 'do_not_translate' => true]),
            $row('01J00000000000000000000002', ['source_locale' => 'de', 'term' => 'Billig', 'forbidden' => '["Billig","Billig*"]', 'concept_id' => $conceptId, 'note' => 'Nie']),
            $row('01J00000000000000000000003', ['source_locale' => 'en', 'term' => 'value', 'translations' => '{"de":"Billig"}', 'concept_id' => $conceptId, 'note' => 'Nie', 'source_aliases' => '["values"]']),
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_05_100000_normalize_ai_glossary_terms.php');
    }

    #[Test]
    public function older_terms_convert_keep_their_concept_and_stay_archived(): void
    {
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes(Space::factory()->create()), 'name' => 'Shop']);
        $held = AiGlossaryConcept::query()->create(['glossary_id' => $glossary->id, 'key' => 'value']);
        $empty = AiGlossaryConcept::query()->create(['glossary_id' => $glossary->id, 'key' => 'gone']);
        $this->oldTables($glossary->id, $held->id);

        // Twice, as a rerun after a failure would.
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertSame(3, DB::table('ai_glossary_terms_legacy')->count(), 'The old rows stay.');
        $this->assertNull(AiGlossaryConcept::query()->find($empty->id), 'A key whose wording was all removed goes.');

        $linked = AiGlossaryConcept::query()->with('terms')->findOrFail($held->id);
        $this->assertSame('value', $linked->key);
        $this->assertSame('Nie', $linked->note);
        $this->assertFalse($linked->keep_exact);
        $terms = $linked->terms->keyBy('locale');
        $this->assertNull($terms['de']->preferred);
        $this->assertSame(['Billig', 'Billig*'], $terms['de']->forbidden);
        $this->assertSame(['Billig'], $terms['de']->aliases, 'A translation that disagrees with a ban stays as Also written as.');
        $this->assertSame('value', $terms['en']->preferred);
        $this->assertSame(['values'], $terms['en']->aliases);

        // The IDs an SDK holds still reach the same language, and only a translation gets a new one.
        $this->assertSame('01J00000000000000000000002', $linked->terms->firstWhere('locale', 'de')->id);
        $this->assertSame('01J00000000000000000000003', $linked->terms->firstWhere('locale', 'en')->id);

        $unlinked = AiGlossaryConcept::query()->with('terms')->findOrFail('01J00000000000000000000001');
        $this->assertSame('01J00000000000000000000001', $unlinked->terms->firstWhere('locale', 'en')->id);
        $this->assertTrue($unlinked->keep_exact);
        $this->assertSame('Shop', $unlinked->note);
        $terms = $unlinked->terms->keyBy('locale');
        $this->assertSame('cart', $terms['en']->preferred);
        $this->assertSame('Warenkorb', $terms['de']->preferred);
        $this->assertSame(['basket'], $terms['de']->forbidden);
        $this->assertSame(2, $glossary->concepts()->count());
    }

    private function oldGlossary(): AiGlossary
    {
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes(Space::factory()->create()), 'name' => 'Shop']);
        $this->oldTables($glossary->id, AiGlossaryConcept::query()->create(['glossary_id' => $glossary->id])->id);

        return $glossary;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function oldRow(AiGlossary $glossary, string $id, array $fields): void
    {
        DB::table('ai_glossary_terms')->insert(['id' => $id, 'glossary_id' => $glossary->id, 'source_locale' => 'en', ...$fields]);
    }

    #[Test]
    public function the_case_setting_and_long_translations_convert_exactly(): void
    {
        $glossary = $this->oldGlossary();
        $long = str_repeat('Größenänderung ', 40);
        $this->oldRow($glossary, '01J00000000000000000000004', ['term' => 'Cart', 'case_sensitive' => true, 'forbidden' => '["cart"]', 'translations' => json_encode(['de' => $long], JSON_THROW_ON_ERROR)]);
        $this->oldRow($glossary, '01J00000000000000000000005', ['term' => 'Acme', 'do_not_translate' => true]);
        $this->oldRow($glossary, '01J00000000000000000000006', ['term' => 'Müll', 'forbidden' => '["Schrott","Müll"]']);

        $this->migration()->up();

        $cart = AiGlossaryConcept::query()->with('terms')->findOrFail('01J00000000000000000000004');
        $this->assertTrue($cart->caseSensitive());
        $this->assertFalse($cart->keep_exact, 'Translation stays allowed.');
        $this->assertSame($long, $cart->terms->firstWhere('locale', 'de')->preferred);

        $checks = PolicySnapshot::fromConcepts([GlossaryConcept::fromModel($cart)]);
        $this->assertCount(1, $checks->activate('Your Cart', 'en', 'de'));
        $this->assertCount(1, (new GlossaryChecker)->check('', 'your cart', 'en', 'en', $checks->concepts), 'Its Avoid in other case still flags.');
        $this->assertSame([], (new GlossaryChecker)->check('', 'your Cart', 'en', 'en', $checks->concepts));

        $acme = AiGlossaryConcept::query()->findOrFail('01J00000000000000000000005');
        $this->assertTrue($acme->keep_exact);
        $this->assertFalse($acme->caseSensitive());

        $ban = AiGlossaryConcept::query()->with('terms')->findOrFail('01J00000000000000000000006')->terms->first();
        $this->assertSame(['Müll', 'Schrott'], $ban->forbidden, 'A ban goes by its term, so that stays first.');
    }

    #[Test]
    public function language_codes_the_new_table_cannot_hold_stop_it_before_anything_changes(): void
    {
        $glossary = $this->oldGlossary();
        $this->oldRow($glossary, '01J00000000000000000000007', ['term' => 'a', 'translations' => json_encode(['' => 'x', str_repeat('y', 21) => 'x', 'de de' => 'x', 'fr' => 'x', 'blank' => ' '], JSON_THROW_ON_ERROR)]);
        $this->oldRow($glossary, '01J00000000000000000000008', ['term' => 'b', 'source_locale' => '!']);

        try {
            $this->migration()->up();
            $this->fail('It should have stopped.');
        } catch (RuntimeException $problem) {
            $message = $problem->getMessage();
        }

        $this->assertStringContainsString('term 01J00000000000000000000007, translations ""', $message);
        $this->assertStringContainsString('translations "de de"', $message);
        $this->assertStringContainsString('term 01J00000000000000000000008, source_locale "!"', $message);
        $this->assertStringContainsString($glossary->id, $message);
        $this->assertStringNotContainsString('"blank"', $message, 'A key without wording converts to nothing.');
        $this->assertStringNotContainsString('"fr"', $message);
        $this->assertFalse(Schema::hasTable('ai_glossary_terms_legacy'));
    }

    #[Test]
    public function a_run_that_stopped_partway_is_completed_step_by_step(): void
    {
        $glossary = $this->oldGlossary();
        $migration = $this->migration();

        // Stopped after adding note, before keep_exact.
        Schema::table('ai_glossary_concepts', fn (Blueprint $table) => $table->dropColumn(['keep_exact', 'case_sensitive']));
        $this->assertTrue(Schema::hasColumn('ai_glossary_concepts', 'note'));
        Schema::rename('ai_glossary_terms', 'ai_glossary_terms_held');

        // Stopped after the rename, before the archive's foreign key, and with a bare new table.
        Schema::create('ai_glossary_terms_legacy', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('glossary_id');
            $table->string('source_locale', 20);
            $table->string('term');
            $table->longText('translations')->nullable();
            $table->boolean('case_sensitive')->default(false);
            $table->boolean('do_not_translate')->default(false);
            $table->longText('forbidden')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->ulid('concept_id')->nullable();
            $table->longText('source_aliases')->nullable();
        });
        DB::table('ai_glossary_terms_legacy')->insertUsing(
            ['id', 'glossary_id', 'source_locale', 'term', 'translations', 'case_sensitive', 'do_not_translate', 'forbidden', 'note', 'created_at', 'updated_at', 'concept_id', 'source_aliases'],
            DB::table('ai_glossary_terms_held')->select(['id', 'glossary_id', 'source_locale', 'term', 'translations', 'case_sensitive', 'do_not_translate', 'forbidden', 'note', 'created_at', 'updated_at', 'concept_id', 'source_aliases']),
        );
        Schema::drop('ai_glossary_terms_held');
        Schema::create('ai_glossary_terms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('concept_id');
            $table->string('locale', 20);
            $table->longText('preferred')->nullable();
            $table->longText('forbidden')->nullable();
            $table->longText('aliases')->nullable();
            $table->timestamps();
        });

        $migration->up();

        $this->assertTrue(Schema::hasColumn('ai_glossary_concepts', 'keep_exact'));
        $this->assertTrue(Schema::hasColumn('ai_glossary_concepts', 'case_sensitive'));
        $this->assertSame([['glossary_id']], array_column(Schema::getForeignKeys('ai_glossary_terms_legacy'), 'columns'));
        $this->assertSame([['concept_id']], array_column(Schema::getForeignKeys('ai_glossary_terms'), 'columns'));
        $this->assertTrue(collect(Schema::getIndexes('ai_glossary_terms'))->contains(fn (array $index) => $index['unique'] && $index['columns'] === ['concept_id', 'locale']));
        $this->assertSame(['de', 'en'], $glossary->concepts()->findOrFail('01J00000000000000000000001')->terms()->orderBy('locale')->pluck('locale')->all());
    }

    #[Test]
    public function rolling_back_restores_the_old_table_as_it_was_converted(): void
    {
        $glossary = $this->oldGlossary();
        $migration = $this->migration();
        $migration->up();

        // Written after the conversion, so a rollback loses it, as its comment says.
        DB::table('ai_glossary_terms')->where('id', '01J00000000000000000000001')->update(['preferred' => 'trolley']);

        $migration->down();

        $this->assertFalse(Schema::hasTable('ai_glossary_terms_legacy'));
        $this->assertFalse(Schema::hasColumn('ai_glossary_concepts', 'note'));
        $this->assertFalse(Schema::hasColumn('ai_glossary_concepts', 'case_sensitive'));
        $this->assertFalse(Schema::hasColumn('ai_glossaries', 'write_version'));
        $this->assertEqualsCanonicalizing([['glossary_id'], ['concept_id']], array_column(Schema::getForeignKeys('ai_glossary_terms'), 'columns'));

        $row = DB::table('ai_glossary_terms')->where('id', '01J00000000000000000000001')->first();
        $this->assertSame('cart', $row->term);
        $this->assertSame('{"de":"Warenkorb"}', $row->translations);
        $this->assertNull($row->concept_id);
        $this->assertSame(3, DB::table('ai_glossary_terms')->where('glossary_id', $glossary->id)->count());
        $this->assertNull(DB::table('ai_glossary_concepts')->find('01J00000000000000000000001'), 'A concept made only for the conversion goes.');
    }
}
