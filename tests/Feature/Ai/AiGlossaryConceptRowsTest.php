<?php

namespace Tests\Feature\Ai;

use App\Exceptions\GlossaryConceptConflict;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WritesGlossaryRows;

class AiGlossaryConceptRowsTest extends TestCase
{
    use RefreshDatabase;
    use WritesGlossaryRows;

    private Space $space;

    private AiGlossary $glossary;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'de',
            'languages' => [['code' => 'en', 'name' => 'English'], ['code' => 'de-AT', 'name' => 'Austrian German']],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'admin');
        Sanctum::actingAs($this->user);

        $this->glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop']);
        $this->base = "/mgmt/v1/spaces/{$this->space->id}/ai/glossaries/{$this->glossary->id}";
    }

    /** @param  array<string, mixed>  $extra */
    private function create(array $extra = []): TestResponse
    {
        return $this->postJson("{$this->base}/concept-rows", [
            'languages' => ['de' => ['use' => 'Wolkenbett'], 'en' => ['use' => 'Cloud bed'], 'fr' => ['use' => 'Lit nuage']],
            ...$extra,
        ])->assertCreated();
    }

    private function term(string $locale, ?AiGlossary $glossary = null): AiGlossaryTerm
    {
        return AiGlossaryTerm::query()->inGlossary(($glossary ?? $this->glossary)->id)->where('locale', $locale)->firstOrFail();
    }

    private function row(string $wording, string $locale = 'de'): string
    {
        return $this->glossaryRow($this->glossary, [$locale => $wording]);
    }

    #[Test]
    public function keep_exact_and_the_note_belong_to_the_row(): void
    {
        $id = $this->create(['keep_exact' => true, 'note' => 'Nur für die Serie.'])
            ->assertJsonPath('data.keep_exact', true)
            ->assertJsonPath('data.note', 'Nur für die Serie.')
            ->json('data.id');
        $this->assertTrue(AiGlossaryConcept::query()->findOrFail($id)->keep_exact);

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['keep_exact' => false])->assertOk()->assertJsonPath('data.keep_exact', false);
        $this->patchJson("{$this->base}/concept-rows/{$id}", ['note' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonPath('code', 'note_too_long');

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['languages' => ['it' => ['use' => 'Letto']]])
            ->assertOk()->assertJsonPath('data.note', 'Nur für die Serie.');

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['note' => null])->assertOk()->assertJsonPath('data.note', null);

        $long = str_repeat('Alt. ', 100);
        AiGlossaryConcept::query()->whereKey($id)->update(['note' => $long]);
        $this->getJson("{$this->base}/concept-rows/{$id}")->assertOk()->assertJsonPath('data.note', $long);
    }

    #[Test]
    public function a_language_with_avoid_and_no_use_is_a_ban_and_holds_no_copy_of_its_wording(): void
    {
        $id = $this->postJson("{$this->base}/concept-rows", ['languages' => [
            'de' => ['avoid' => ['Billigbett', 'Billig*']],
            'en' => ['use' => 'value bed'],
        ]])->assertCreated()
            ->assertJsonPath('data.languages.de.use', null)
            ->assertJsonPath('data.languages.de.avoid', ['Billigbett', 'Billig*'])
            ->json('data.id');

        $this->assertNull($this->term('de')->preferred);
        $this->assertSame(['Billigbett', 'Billig*'], $this->term('de')->forbidden);

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['languages' => ['de' => ['use' => 'Sparbett']]])
            ->assertOk()->assertJsonPath('data.languages.de.use', 'Sparbett');

        // Clearing the Use goes back to a ban.
        $this->patchJson("{$this->base}/concept-rows/{$id}", ['languages' => ['de' => ['use' => null]]])
            ->assertOk()->assertJsonPath('data.languages.de.use', null);

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['languages' => ['de' => ['avoid' => []]]])
            ->assertUnprocessable()->assertJsonPath('code', 'term_required');
    }

    #[Test]
    public function the_same_wording_cannot_be_both_use_and_avoid(): void
    {
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Bett', 'avoid' => ['Bett']]]])
            ->assertUnprocessable()->assertJsonPath('code', 'use_avoided')->assertJsonPath('locale', 'de');
        $this->assertSame(0, $this->glossary->terms()->count());

        $this->postJson("{$this->base}/concept-rows", ['keep_exact' => true, 'languages' => ['de' => ['use' => 'Acme', 'avoid' => ['ACME']]]])
            ->assertCreated()->assertJsonPath('data.languages.de.avoid', ['ACME']);
    }

    #[Test]
    public function a_save_cannot_replace_a_value_its_author_never_saw(): void
    {
        $read = $this->create(['note' => 'Alt', 'languages' => ['de' => ['use' => 'Wolkenbett'], 'en' => ['use' => 'Cloud bed']]])->json('data');
        $url = "{$this->base}/concept-rows/{$read['id']}";
        $base = ['keep_exact' => $read['keep_exact'], 'note' => $read['note'], 'languages' => $read['languages']];

        $this->patchJson($url, ['note' => 'Neu', 'languages' => ['de' => ['use' => 'Traumbett']]])->assertOk();

        $this->patchJson($url, ['note' => 'Meins', 'languages' => ['de' => ['use' => 'Federbett']], 'base' => $base])
            ->assertConflict()
            ->assertJsonPath('code', 'row_changed')
            ->assertJsonPath('changes', [
                ['field' => 'note', 'locale' => null],
                ['field' => 'use', 'locale' => 'de'],
            ]);
        $this->assertSame('Traumbett', $this->term('de')->preferred);

        $this->patchJson($url, ['remove_languages' => ['de'], 'base' => $base])
            ->assertConflict()->assertJsonPath('changes.0.field', 'use');

        // Saving another field, or the value already stored, overwrites nothing.
        $this->patchJson($url, ['languages' => ['en' => ['use' => 'Dream bed'], 'de' => ['use' => 'Traumbett']], 'base' => $base])
            ->assertOk()->assertJsonPath('data.languages.en.use', 'Dream bed');

        $newer = ['note' => 'Neu', 'keep_exact' => false, 'languages' => ['de' => ['use' => 'Traumbett', 'avoid' => [], 'also' => []]]];
        $this->patchJson($url, ['languages' => ['de' => ['use' => 'Federbett']], 'base' => $newer])
            ->assertOk()->assertJsonPath('data.languages.de.use', 'Federbett');
    }

    #[Test]
    public function an_older_long_note_comes_back_unchanged_and_a_new_or_changed_one_is_refused(): void
    {
        $this->postJson("{$this->base}/concept-rows", ['note' => str_repeat('x', 201), 'languages' => ['de' => ['use' => 'Sofa']]])
            ->assertUnprocessable()->assertJsonPath('code', 'note_too_long');

        $read = $this->create(['note' => 'Kurz', 'languages' => ['de' => ['use' => 'Bett']]])->json('data');
        $url = "{$this->base}/concept-rows/{$read['id']}";
        $older = str_repeat('y', 2001);
        AiGlossaryConcept::query()->whereKey($read['id'])->update(['note' => $older]);

        $this->patchJson($url, ['note' => $older, 'languages' => ['de' => ['use' => 'Federbett']]])
            ->assertOk()->assertJsonPath('data.languages.de.use', 'Federbett');
        $this->patchJson($url, ['note' => "  {$older}  "])->assertOk();
        $this->assertSame($older, AiGlossaryConcept::query()->findOrFail($read['id'])->note);

        $this->patchJson($url, ['note' => "{$older}z"])
            ->assertUnprocessable()->assertJsonPath('code', 'note_too_long');
        $this->assertSame($older, AiGlossaryConcept::query()->findOrFail($read['id'])->note);

        $this->patchJson($url, ['note' => str_repeat('z', 200)])->assertOk();
    }

    #[Test]
    public function a_partial_write_keeps_what_it_omits_and_a_language_goes_only_by_explicit_removal(): void
    {
        $id = $this->create([
            'key' => 'cloud-bed',
            'note' => 'Hinweis',
            'languages' => ['de' => ['use' => 'Wolkenbett', 'also' => ['WB'], 'avoid' => ['Luftbett']], 'en' => ['use' => 'Cloud bed']],
        ])->json('data.id');

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['languages' => ['de' => ['also' => ['Wolkenbetten']]]])
            ->assertOk()
            ->assertJsonPath('data.key', 'cloud-bed')
            ->assertJsonPath('data.note', 'Hinweis')
            ->assertJsonPath('data.languages.de.use', 'Wolkenbett')
            ->assertJsonPath('data.languages.de.also', ['Wolkenbetten'])
            ->assertJsonPath('data.languages.de.avoid', ['Luftbett'])
            ->assertJsonPath('data.languages.en.use', 'Cloud bed');

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['remove_languages' => ['en']])
            ->assertOk()
            ->assertJsonMissingPath('data.languages.en');

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['remove_languages' => ['de']])
            ->assertStatus(422)->assertJsonPath('code', 'last_language');
    }

    #[Test]
    public function a_wording_of_another_concept_is_refused_and_names_it(): void
    {
        $other = $this->postJson("{$this->base}/concept-rows", ['key' => 'held', 'languages' => ['de' => ['use' => 'Bett'], 'en' => ['use' => 'Bed']]])->assertCreated()->json('data.id');
        $id = $this->create()->json('data.id');

        $this->patchJson("{$this->base}/concept-rows/{$id}", ['key' => 'moved', 'languages' => ['de' => ['use' => 'BETT']]])
            ->assertConflict()
            ->assertJsonPath('code', 'wording_conflict')
            ->assertJsonPath('locale', 'de')
            ->assertJsonPath('concept', ['id' => $other, 'key' => 'held', 'term' => 'Bett']);

        $this->assertNull(AiGlossaryConcept::query()->find($id)->key);
        $this->assertSame(1, AiGlossaryTerm::query()->where('preferred', 'Wolkenbett')->count());

        // The same wording in another locale, or a new spelling of its own, is fine.
        $this->patchJson("{$this->base}/concept-rows/{$id}", ['languages' => ['fr' => ['use' => 'Bett'], 'de' => ['use' => 'WOLKENBETT']]])->assertOk();

        $this->postJson("{$this->base}/concept-rows", ['key' => 'held', 'languages' => ['de' => ['use' => 'Neu']]])
            ->assertConflict()->assertJsonPath('code', 'key_conflict')->assertJsonPath('concept.id', $other);
    }

    #[Test]
    public function deleting_a_row_removes_its_terms_and_its_concept(): void
    {
        $id = $this->create()->json('data.id');
        $this->deleteJson("{$this->base}/concept-rows/{$id}")->assertNoContent();
        $this->assertSame(0, $this->glossary->terms()->count());
        $this->assertSame(0, AiGlossaryConcept::query()->count());
    }

    #[Test]
    public function bulk_deletes_selected_rows_in_one_transaction(): void
    {
        $first = $this->create()->json('data.id');
        $second = $this->row('Zwei');

        $this->postJson("{$this->base}/concept-rows/bulk", ['row_ids' => [$first], 'action' => 'set_status'])->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->postJson("{$this->base}/concept-rows/bulk", ['row_ids' => [$first, 'unknown'], 'action' => 'delete'])->assertUnprocessable()->assertJsonValidationErrors('row_ids');
        $this->assertSame(4, $this->glossary->terms()->count());

        $this->postJson("{$this->base}/concept-rows/bulk", ['row_ids' => [$first, $second], 'action' => 'delete'])
            ->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSame(0, $this->glossary->terms()->count());
    }

    #[Test]
    public function a_base_language_covers_its_regions(): void
    {
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Bett']]])
            ->assertCreated()
            ->assertJsonPath('data.coverage', ['de' => 'own', 'en' => 'missing', 'de-at' => 'fallback']);

        $this->postJson("{$this->base}/concept-rows", ['languages' => ['en' => ['use' => 'Table']]])->assertCreated();

        $this->assertSame(['Table'], array_column($this->getJson("{$this->base}/concept-rows?missing=de-AT")->assertOk()->json('data.*.languages.en'), 'use'));
    }

    #[Test]
    public function locales_come_from_the_space_with_stored_others_flagged_unused_and_from_use_in_a_team(): void
    {
        $this->create();

        $available = $this->getJson("{$this->base}/concept-rows")->assertOk()->json('meta.available_locales');
        $this->assertSame(['de', 'en', 'de-at', 'fr'], array_column($available, 'code'));
        $this->assertTrue($available[0]['default']);
        $this->assertSame([false, false, false, true], array_column($available, 'unused'));
        $this->assertFalse($available[3]['enabled']);

        $team = Team::factory()->create();
        $this->assignTeamRole($team, $this->user, 'owner');
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes($team), 'name' => 'Team']);
        $base = "/mgmt/v1/teams/{$team->id}/ai/glossaries/{$glossary->id}/concept-rows";

        $this->postJson($base, ['languages' => ['pt' => ['use' => 'Cama'], 'it' => ['use' => 'Letto']]])->assertCreated();
        $available = $this->getJson($base)->assertOk()->json('meta.available_locales');
        $this->assertSame(['it', 'pt'], array_column($available, 'code'));
        $this->assertSame([false, false], array_column($available, 'unused'));
    }

    #[Test]
    public function rows_search_sort_and_report_facets(): void
    {
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Zebra'], 'en' => ['use' => 'Zebra rug', 'avoid' => ['Stripe mat']]]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['note' => 'Ein Grünzeug', 'languages' => ['de' => ['use' => 'Äpfel', 'also' => ['Süßfrucht']]]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['en' => ['use' => 'Aardvark']]])->assertCreated();
        $this->row('Banane');

        $terms = fn (string $query) => array_map(
            fn (array $row) => reset($row['languages'])['use'],
            $this->getJson("{$this->base}/concept-rows{$query}")->assertOk()->json('data'),
        );

        // In the default language's alphabet, then rows that have no term in it.
        $this->assertSame(['Äpfel', 'Banane', 'Zebra', 'Aardvark'], $terms(''));
        $this->assertSame(['Äpfel'], $terms('?search='.urlencode('süßfrucht')));
        $this->assertSame(['Äpfel'], $terms('?search='.urlencode('GRÜNZEUG')));
        $this->assertSame(['Zebra'], $terms('?search=rug'));
        $this->assertSame(['Zebra'], $terms('?search='.urlencode('stripe mat')));
        $this->assertSame(['Aardvark'], $terms('?missing=de'));
        $this->assertSame(['Banane'], $terms('?per_page=1&page=2'));

        $facets = $this->getJson("{$this->base}/concept-rows?search=rug")->assertOk()->assertJsonPath('meta.total', 1)->json('meta.facets');
        $this->assertSame(['total', 'locales', 'missing'], array_keys($facets));
        $this->assertSame([['value' => 'de', 'count' => 3], ['value' => 'en', 'count' => 2]], $facets['locales']);
    }

    #[Test]
    public function one_row_reads_by_id_and_facets_count_what_each_language_misses(): void
    {
        $id = $this->create()->json('data.id');
        $legacy = $this->row('Sofa');

        $this->getJson("{$this->base}/concept-rows/{$id}")
            ->assertOk()
            ->assertJsonPath('data.languages.en.use', 'Cloud bed');
        $this->getJson("{$this->base}/concept-rows/{$legacy}")
            ->assertOk()
            ->assertJsonPath('data.languages.de.use', 'Sofa');
        $this->getJson("{$this->base}/concept-rows/unknown")->assertNotFound();

        $this->getJson("{$this->base}/concept-rows?search=sofa")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.facets.total', 2)
            ->assertJsonPath('meta.facets.missing', [
                ['value' => 'de', 'count' => 0],
                ['value' => 'de-at', 'count' => 0],
                ['value' => 'en', 'count' => 1],
            ]);
    }

    #[Test]
    public function an_inherited_glossary_reads_but_stays_read_only_and_another_owners_is_not_found(): void
    {
        $team = Team::factory()->create();
        $this->space->forceFill(['team_id' => $team->id])->save();
        $inherited = AiGlossary::create([...AiGlossary::ownerAttributes($team), 'name' => 'Team']);
        $held = $this->glossaryRow($inherited, ['de' => 'Bett']);
        $base = "/mgmt/v1/spaces/{$this->space->id}/ai/glossaries/{$inherited->id}";

        $this->getJson("{$base}/concept-rows")->assertOk()->assertJsonPath('meta.read_only', true)->assertJsonPath('data.0.languages.de.use', 'Bett');
        $this->postJson("{$base}/glossary-check", ['text' => 'Ein Bett', 'source_locale' => 'de'])->assertOk()->assertJsonPath('data.terms.0.term', 'Bett');

        $this->postJson("{$base}/concept-rows", ['languages' => ['de' => ['use' => 'Neu']]])->assertForbidden();
        $this->patchJson("{$base}/concept-rows/{$held}", ['note' => 'Neu'])->assertForbidden();
        $this->deleteJson("{$base}/concept-rows/{$held}")->assertForbidden();
        $this->postJson("{$base}/concept-rows/bulk", ['row_ids' => [$held], 'action' => 'delete'])->assertForbidden();
        $this->assertNotNull(AiGlossaryConcept::query()->find($held));

        $foreign = AiGlossary::create([...AiGlossary::ownerAttributes(Space::factory()->create()), 'name' => 'Other']);
        $base = "/mgmt/v1/spaces/{$this->space->id}/ai/glossaries/{$foreign->id}";
        $this->getJson("{$base}/concept-rows")->assertNotFound();
        $this->postJson("{$base}/glossary-check", ['text' => 'x', 'source_locale' => 'de'])->assertNotFound();
        $this->postJson("{$base}/concept-rows", ['languages' => ['de' => ['use' => 'Neu']]])->assertNotFound();

        $this->patchJson("{$this->base}/concept-rows/{$held}", ['note' => 'Neu'])->assertNotFound();
    }

    #[Test]
    public function the_check_reports_offsets_the_instruction_and_findings_without_a_provider(): void
    {
        $this->postJson("{$this->base}/concept-rows", ['note' => 'Nur Serie X', 'languages' => [
            'de' => ['use' => 'Wolkenbett', 'also' => ['WB'], 'avoid' => ['Luftbett']],
            'en' => ['use' => 'Cloud bed', 'avoid' => ['air mattress']],
        ]])->assertCreated();
        $outside = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Other']);
        $this->glossaryRow($outside, ['de' => 'Lieferung']);

        $text = '😀 Das Wolkenbett und WB, Lieferung';
        $check = $this->postJson("{$this->base}/glossary-check", [
            'text' => $text,
            'source_locale' => 'de',
            'target_locale' => 'en',
            'output' => 'The air mattress',
        ])->assertOk();

        $term = $check->json('data.terms.0');
        $this->assertCount(1, $check->json('data.terms'));
        $this->assertSame('Cloud bed', $term['required']);
        $this->assertSame('"Wolkenbett": write "Cloud bed" in en; never use "air mattress"; note: Nur Serie X', $term['instruction']);
        $this->assertSame([
            ['start' => 7, 'end' => 17, 'text' => 'Wolkenbett'],
            ['start' => 22, 'end' => 24, 'text' => 'WB'],
        ], $term['matches']);

        $this->assertEqualsCanonicalizing(['missing_term', 'forbidden_variant'], array_column($check->json('data.findings'), 'type'));
        $this->assertSame('Cloud bed', collect($check->json('data.findings'))->firstWhere('type', 'forbidden_variant')['expected']);

        $this->postJson("{$this->base}/glossary-check", ['text' => $text, 'source_locale' => 'de'])
            ->assertOk()->assertJsonPath('data.findings', null);
    }

    #[Test]
    public function the_check_skips_a_wording_inside_a_longer_one_and_tells_a_source_that_wrote_avoid_wording(): void
    {
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Acme', 'also' => ['acme'], 'avoid' => ['Akme']]]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Großes Wolkenbett']]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Wolkenbett']]])->assertCreated();

        $check = $this->postJson("{$this->base}/glossary-check", ['text' => 'Das Große Wolkenbett, Großes Wolkenbett von Akme.', 'source_locale' => 'de'])->assertOk();

        $this->assertEqualsCanonicalizing(['Akme', 'Großes Wolkenbett', 'Wolkenbett'], array_column($check->json('data.terms'), 'term'));
        $brand = collect($check->json('data.terms'))->firstWhere('term', 'Akme');
        $this->assertSame('"Akme": never use; write "Acme"', $brand['instruction']);
        $this->assertTrue($brand['avoided']);
    }

    #[Test]
    public function a_search_ranks_exact_wording_then_starts_with_then_contains_then_avoid_also_and_note(): void
    {
        $this->postJson("{$this->base}/concept-rows", ['note' => 'Wie ein Sofa', 'languages' => ['de' => ['use' => 'Abdeckung']]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Eckiges Sofa']]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Sofakissen']]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Couch', 'also' => ['Sofa']]]])->assertCreated();
        $this->postJson("{$this->base}/concept-rows", ['languages' => ['de' => ['use' => 'Zimmer'], 'en' => ['use' => 'Sofa']]])->assertCreated();

        $terms = array_map(
            fn (array $row) => $row['languages']['de']['use'],
            $this->getJson("{$this->base}/concept-rows?search=sofa")->assertOk()->json('data'),
        );

        $this->assertSame(['Zimmer', 'Sofakissen', 'Eckiges Sofa', 'Abdeckung', 'Couch'], $terms, 'Exact in any language, starts with, contains, then the rest in the usual order.');
    }

    #[Test]
    public function a_batch_writes_rows_without_a_query_per_row_and_reports_refused_rows(): void
    {
        $this->row('Altbestand');

        $write = function (int $rows, string $prefix) {
            $concepts = GlossaryConcepts::open($this->glossary);
            $connection = DB::connection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();

            for ($i = 0; $i < $rows; $i++) {
                $concepts->create(['languages' => ['de' => ['use' => "{$prefix} {$i}"], 'en' => ['use' => "{$prefix} {$i} en"]]]);
            }

            $during = \count($connection->getQueryLog());
            $concepts->commit();
            $connection->disableQueryLog();

            return [$during, \count($connection->getQueryLog())];
        };

        [$during, $total] = $write(120, 'Reihe');

        $this->assertSame(0, $during);
        $this->assertLessThan(15, $total);
        $this->assertSame(241, $this->glossary->terms()->count());

        $concepts = GlossaryConcepts::open($this->glossary);
        $id = $concepts->rowIdForWording('de', 'REIHE 3');
        $this->assertNotNull($id);

        try {
            $concepts->update($id, ['key' => 'vergeben', 'languages' => ['de' => ['use' => 'Reihe 4']]]);
            $this->fail('A taken wording must refuse the row.');
        } catch (GlossaryConceptConflict $conflict) {
            $this->assertSame(GlossaryConceptConflict::WORDING_TAKEN, $conflict->reason);
        }

        $concepts->update($id, ['languages' => ['de' => ['use' => 'Frei']]]);
        $other = $concepts->rowIdForWording('de', 'Reihe 5');
        $concepts->update($other, ['languages' => ['de' => ['use' => 'Reihe 3']]]);
        $concepts->commit();

        $this->assertNull(AiGlossaryConcept::query()->find($id)->key);
        $this->assertSame($id, AiGlossaryTerm::query()->where('preferred', 'Frei')->sole()->concept_id);

    }

    #[Test]
    public function a_write_in_the_same_second_after_open_makes_a_batch_stale(): void
    {
        Carbon::setTestNow('2026-11-04 12:00:00');
        $id = $this->row('Bett');

        $stale = GlossaryConcepts::open($this->glossary);
        $stale->update($id, ['note' => 'Aus einem langsamen Import.']);

        // Nothing but a version can tell this apart: the row's timestamp does not move.
        $this->changeGlossaryRow($this->glossary, $id, ['de' => 'Sofa']);

        try {
            $stale->commit();
            $this->fail('A batch opened before another write must not store over it.');
        } catch (GlossaryConceptConflict $conflict) {
            $this->assertSame(GlossaryConceptConflict::STALE, $conflict->reason);
        }

        $this->assertNull(AiGlossaryConcept::query()->findOrFail($id)->note);
        $this->assertSame('Sofa', $this->term('de')->preferred);

        // Each committed write moves the version once, and one that writes nothing does not.
        $version = $this->glossary->refresh()->write_version;
        GlossaryConcepts::open($this->glossary)->commit();
        $this->assertSame($version, $this->glossary->refresh()->write_version);
        $this->changeGlossaryRow($this->glossary, $id, ['de' => 'Sessel']);
        $this->assertSame($version + 1, $this->glossary->refresh()->write_version);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_use_its_own_avoid_would_flag_is_refused_by_the_checker_rule(): void
    {
        $this->rowsPost(['en' => ['use' => 'Cart', 'avoid' => ['cart']]])->assertUnprocessable()
            ->assertJsonPath('code', 'use_avoided')->assertJsonPath('avoid', 'cart');
        $this->rowsPost(['de' => ['use' => 'Händler', 'avoid' => ['Händler*']]])->assertUnprocessable()->assertJsonPath('avoid', 'Händler*');

        // Keep exact matches exact case, so this pair holds until it is switched off.
        $row = $this->rowsPost(['en' => ['use' => 'Cart', 'avoid' => ['cart']]], ['keep_exact' => true])->assertCreated()->json('data.id');
        $this->patchJson("{$this->base}/concept-rows/{$row}", ['keep_exact' => false])->assertUnprocessable()->assertJsonPath('code', 'use_avoided');
        $this->assertTrue(AiGlossaryConcept::query()->findOrFail($row)->keep_exact);

        // A contradiction a migrated row holds stays visible and takes other edits.
        $old = AiGlossaryConcept::query()->create(['glossary_id' => $this->glossary->id]);
        AiGlossaryTerm::query()->create(['concept_id' => $old->id, 'locale' => 'en', 'preferred' => 'Bike', 'forbidden' => ['bike']]);
        $this->getJson("{$this->base}/concept-rows/{$old->id}")->assertOk()->assertJsonPath('data.languages.en.avoid', ['bike']);
        $this->patchJson("{$this->base}/concept-rows/{$old->id}", ['note' => 'Old', 'languages' => ['en' => ['also' => ['bikes']], 'de' => ['use' => 'Rad']]])->assertOk();
        $this->patchJson("{$this->base}/concept-rows/{$old->id}", ['languages' => ['en' => ['use' => 'BIKE']]])->assertUnprocessable();
        $this->patchJson("{$this->base}/concept-rows/{$old->id}", ['languages' => ['en' => ['avoid' => []]]])->assertOk();
    }

    /**
     * @param  array<string, array<string, mixed>>  $languages
     * @param  array<string, mixed>  $fields
     */
    private function rowsPost(array $languages, array $fields = []): TestResponse
    {
        return $this->postJson("{$this->base}/concept-rows", [...$fields, 'languages' => $languages]);
    }

    #[Test]
    public function an_inherited_glossary_exports_and_its_writes_stay_with_its_owner(): void
    {
        $team = Team::factory()->create();
        $this->space->forceFill(['team_id' => $team->id])->save();
        $inherited = AiGlossary::create([...AiGlossary::ownerAttributes($team), 'name' => 'Team terms']);
        $this->glossaryRow($inherited, ['de' => 'Lieferung', 'en' => 'delivery']);
        $base = "/mgmt/v1/spaces/{$this->space->id}/ai/glossaries/{$inherited->id}";

        $this->assertStringContainsString('Lieferung', $this->get("{$base}/export?format=csv")->assertOk()->streamedContent());
        $this->assertStringContainsString('Lieferung', $this->get("{$base}/export?format=tbx")->assertOk()->getContent());

        $this->post("{$base}/import", ['file' => UploadedFile::fake()->createWithContent('rows.csv', "term:en\nparcel\n")], ['Accept' => 'application/json'])->assertNotFound();

        $other = AiGlossary::create([...AiGlossary::ownerAttributes(Team::factory()->create()), 'name' => 'Elsewhere']);
        $this->get("/mgmt/v1/spaces/{$this->space->id}/ai/glossaries/{$other->id}/export")->assertNotFound();
    }

    #[Test]
    public function a_language_code_the_table_cannot_hold_is_refused(): void
    {
        $this->rowsPost(['not a locale' => ['use' => 'cart']])->assertJsonValidationErrors('languages');
        $id = $this->rowsPost(['en' => ['use' => 'cart']])->assertCreated()->json('data.id');
        $this->patchJson("{$this->base}/concept-rows/{$id}", ['languages' => [str_repeat('x', 21) => ['use' => 'Warenkorb']]])->assertJsonValidationErrors('languages');
        $this->assertSame(1, AiGlossaryTerm::query()->count());
    }

    #[Test]
    public function the_check_can_answer_for_what_a_run_in_this_space_uses(): void
    {
        $team = Team::factory()->create();
        $this->space->forceFill(['team_id' => $team->id])->save();
        $inherited = AiGlossary::create([...AiGlossary::ownerAttributes($team), 'name' => 'Team terms']);
        $this->glossaryRow($inherited, ['de' => 'Lieferung', 'en' => 'delivery']);
        $this->glossaryRow($this->glossary, ['de' => 'Bett', 'en' => 'bed']);
        $switchedOff = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Off', 'enabled' => false]);
        $this->glossaryRow($switchedOff, ['de' => 'Tisch']);

        $text = ['text' => 'Bett, Lieferung und Tisch', 'source_locale' => 'de', 'target_locale' => 'en'];

        $alone = $this->postJson("{$this->base}/glossary-check", $text)->assertOk()->json('data.terms');
        $this->assertSame(['Bett'], array_column($alone, 'term'));

        $space = $this->postJson("{$this->base}/glossary-check", [...$text, 'scope' => 'space'])->assertOk()->json('data.terms');
        $this->assertEqualsCanonicalizing(['Bett', 'Lieferung'], array_column($space, 'term'));
        $this->assertSame('Team terms', collect($space)->firstWhere('term', 'Lieferung')['glossary_name']);

        $this->assignTeamRole($team, $this->user, 'owner');
        $this->postJson("/mgmt/v1/teams/{$team->id}/ai/glossaries/{$inherited->id}/glossary-check", [...$text, 'scope' => 'space'])->assertStatus(422);
    }

    #[Test]
    public function an_inherited_glossary_lists_with_its_owner(): void
    {
        $team = Team::factory()->create(['name' => 'Acme Group']);
        $this->space->forceFill(['team_id' => $team->id])->save();
        AiGlossary::create([...AiGlossary::ownerAttributes($team), 'name' => 'Team terms']);
        $this->glossaryRow($this->glossary, ['de' => 'Bett', 'en' => 'bed']);

        $list = collect($this->getJson("/mgmt/v1/spaces/{$this->space->id}/ai/glossaries?inherited=1")->assertOk()->json('data'))->keyBy('name');

        $this->assertSame('Acme Group', $list['Team terms']['owner_name']);
        $this->assertSame($this->space->name, $list['Shop']['owner_name']);
        $this->assertSame(1, $list['Shop']['terms_count'], 'A row counts once, whatever its languages.');
    }
}
