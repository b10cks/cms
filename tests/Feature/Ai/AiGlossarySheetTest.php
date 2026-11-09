<?php

namespace Tests\Feature\Ai;

use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use App\Services\Ai\Knowledge\GlossaryBrief;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\Sheet\GlossarySheetExport;
use App\Services\Ai\Knowledge\Sheet\GlossarySheetImport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WritesGlossaryRows;

class AiGlossarySheetTest extends TestCase
{
    use RefreshDatabase;
    use WritesGlossaryRows;

    private Space $space;

    private AiGlossary $glossary;

    private string $base;

    private string $tempDir;

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
        $this->tempDir = sys_get_temp_dir().'/glossary-sheet-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        rmdir($this->tempDir);

        parent::tearDown();
    }

    private function import(string $csv, string $name = 'glossary.csv'): TestResponse
    {
        return $this->post("{$this->base}/import", ['file' => UploadedFile::fake()->createWithContent($name, $csv)], ['Accept' => 'application/json'])->assertOk();
    }

    private function term(string $wording): AiGlossaryTerm
    {
        return AiGlossaryTerm::query()->inGlossary($this->glossary->id)
            ->where(fn ($query) => $query->where('preferred', $wording)->orWhere('forbidden', 'like', '%"'.$wording.'"%'))
            ->firstOrFail();
    }

    private function auditEntries(): Collection
    {
        return AuditLog::query()->where('action', GlossarySheetImport::AUDIT_ACTION)->where('entity_id', $this->glossary->id)->orderBy('id')->get();
    }

    private function exportBody(string $format, string $query = ''): string
    {
        return $this->get("{$this->base}/export?format={$format}{$query}")->assertOk()->streamedContent();
    }

    /** @return array{0: list<string>, 1: list<array<string, string>>} */
    private function parseCsv(string $csv): array
    {
        $lines = array_map(static fn (string $line) => str_getcsv($line, ',', '"', ''), preg_split('/\R/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv))));
        $headers = array_shift($lines);

        return [$headers, array_map(static fn (array $line) => array_combine($headers, $line), $lines)];
    }

    #[Test]
    public function a_row_creates_a_concept_with_every_field(): void
    {
        $this->import(implode("\n", [
            'concept,keep_exact,note,term:de,avoid:de,also:de,term:en',
            'cloud-bed,yes,Nur Serie X,Wolkenbett,Schwebebett; Luft*,WB; Luftbett,Cloud bed',
        ]))
            ->assertJsonPath('data.summary.added', 1)
            ->assertJsonPath('data.summary.updated', 0)
            ->assertJsonPath('data.summary.total_errors', 0);

        $concept = AiGlossaryConcept::query()->where('key', 'cloud-bed')->firstOrFail();
        $de = $this->term('Wolkenbett');

        $this->assertSame($concept->id, $de->concept_id);
        $this->assertSame(['WB', 'Luftbett'], $de->aliases);
        $this->assertSame(['Schwebebett', 'Luft*'], $de->forbidden, 'A star in Avoid wording is kept as typed.');
        $this->assertTrue($concept->keep_exact);
        $this->assertSame('Nur Serie X', $concept->note);
        $this->assertSame($concept->id, $this->term('Cloud bed')->concept_id);
    }

    #[Test]
    public function a_plain_four_term_glossary_reaches_the_prompt_and_the_checker(): void
    {
        $this->import(implode("\n", [
            'term:de,avoid:de,also:de,term:en,avoid:en,keep_exact,note',
            'Bett,,Betten,bed,,,',
            ',Billigbett,,,,,',
            'Acme,,,,,yes,',
            'Sofa,Couchen*,,sofa,settee,,Nur für die Serie X',
        ]))->assertJsonPath('data.summary.added', 4)->assertJsonPath('data.summary.total_errors', 0);

        $this->glossary->update(['enabled' => true]);
        $policy = app(KnowledgeResolver::class)->livePolicy($this->space);
        $source = 'Zwei Betten, ein Billigbett, Acme und die Couchen.';

        $this->assertSame(implode("\n", [
            'Terms found in the source (de). Follow each line.',
            '- "Acme": keep exactly as "Acme"',
            '- "Bett": write "bed" in en',
            '- "Billigbett": never use',
            '- "Couchen": never use; write "sofa" in en; never use "settee"; note: Nur für die Serie X',
            'A note is for your judgment: it applies only where it says so, and nothing checks it.',
        ]), GlossaryBrief::render($policy->activate($source, 'de', 'en'), 'de', 'en'));

        $checker = new GlossaryChecker;
        $this->assertSame([], $checker->check($source, 'A bed, Acme and a sofa.', 'de', 'en', $policy->concepts));
        $this->assertEqualsCanonicalizing([
            ['type' => 'translated_do_not_translate', 'term' => 'Acme', 'expected' => 'Acme'],
            ['type' => 'missing_term', 'term' => 'Bett', 'expected' => 'bed'],
            ['type' => 'missing_term', 'term' => 'Sofa', 'expected' => 'sofa'],
            ['type' => 'forbidden_variant', 'term' => 'sofa', 'expected' => 'sofa', 'found' => 'settee'],
        ], $checker->check($source, 'Two cots and a settee.', 'de', 'en', $policy->concepts));
        $this->assertSame([
            ['type' => 'forbidden_variant', 'term' => 'Billigbett', 'found' => 'Billigbett'],
            ['type' => 'forbidden_variant', 'term' => 'Sofa', 'expected' => 'Sofa', 'found' => 'Couchen'],
        ], $checker->check('', 'Ein Billigbett und Couchen.', 'de', 'de', $policy->concepts));
    }

    #[Test]
    public function a_file_with_only_term_columns_works(): void
    {
        $this->import("term:de,term:en\nBett,Bed\nSofa,Couch\n")
            ->assertJsonPath('data.summary.added', 2)
            ->assertJsonPath('data.summary.total_errors', 0)
            ->assertJsonPath('data.ignored_fields', []);

        $this->assertSame($this->term('Bett')->concept_id, $this->term('Bed')->concept_id);
    }

    #[Test]
    public function avoid_without_a_term_is_a_ban_and_a_row_with_neither_is_a_row_error(): void
    {
        $this->import("avoid:de,also:de,note\nBilligbett; Billig*,Billigbetten,Nie\n,Nur Also,\n,,Nur Notiz\n")
            ->assertJsonPath('data.summary.added', 1)
            ->assertJsonPath('data.errors.0.row', 3)
            ->assertJsonPath('data.errors.1.row', 4);

        $ban = $this->term('Billigbett');
        $this->assertNull($ban->preferred);
        $this->assertSame(['Billigbett', 'Billig*'], $ban->forbidden);

        [, $rows] = $this->parseCsv($this->exportBody('csv'));
        $this->assertSame('', $rows[0]['term:de'], 'The export shows a ban with no term.');
        $this->assertSame('Billigbett; Billig*', $rows[0]['avoid:de']);

        $this->import($this->exportBody('csv'))->assertJsonPath('data.summary.unchanged', 1)->assertJsonPath('data.summary.total_errors', 0);
    }

    #[Test]
    public function a_note_over_the_limit_is_a_row_error_and_an_older_long_note_reads_back_unchanged(): void
    {
        $long = str_repeat('x', 201);

        $this->import("term:de,note\nBett,{$long}\n")
            ->assertJsonPath('data.summary.added', 0)
            ->assertJsonPath('data.errors.0.column', 'note')
            ->assertJsonPath('data.errors.0.message', 'The note is longer than 200 characters.');

        $id = $this->glossaryRow($this->glossary, ['de' => 'Sofa']);
        AiGlossaryConcept::query()->whereKey($id)->update(['note' => $long]);

        $this->import($this->exportBody('csv'))->assertJsonPath('data.summary.unchanged', 1)->assertJsonPath('data.summary.total_errors', 0);
        $this->assertSame($long, $this->term('Sofa')->concept->note);

        $this->import("term:de,note\nSofa,{$long}z\n")
            ->assertJsonPath('data.errors.0.column', 'note')
            ->assertJsonPath('data.summary.updated', 0);
        $this->assertSame($long, $this->term('Sofa')->concept->note);
    }

    #[Test]
    public function an_older_long_note_with_outer_spaces_reimports_without_an_error(): void
    {
        $long = str_repeat('x', 201);
        $id = $this->glossaryRow($this->glossary, ['de' => 'Sofa']);
        AiGlossaryConcept::query()->whereKey($id)->update(['note' => "  {$long}  "]);

        $this->import($this->exportBody('csv'))->assertJsonPath('data.summary.total_errors', 0);
        $this->import("source_locale,term,note\nde,Sofa,{$long}\n", 'g.csv')->assertJsonPath('data.summary.total_errors', 0);

        $this->assertSame($long, trim((string) $this->term('Sofa')->concept->note));
    }

    #[Test]
    public function the_old_sheet_columns_are_reported_once_as_unknown(): void
    {
        $this->import("term:de,category,status,audience,protected,case_sensitive,aliases:de,banned:de,definition:de,notes:de,status:de,audience:de\nBett,Möbel,prohibited,B2B,yes,yes,A,B,C,D,contextual,Intern\nSofa,,,,,,,,,,,\n")
            ->assertJsonPath('data.ignored_fields', ['category', 'status', 'audience', 'protected', 'case_sensitive', 'aliases:de', 'banned:de', 'definition:de', 'notes:de', 'status:de', 'audience:de'])
            ->assertJsonPath('data.summary.added', 2)
            ->assertJsonPath('data.summary.total_errors', 0);

        $this->assertSame('Bett', $this->term('Bett')->preferred);
        $this->assertSame([], $this->term('Bett')->forbidden ?? []);
    }

    #[Test]
    public function blank_cells_and_missing_rows_keep_what_is_stored(): void
    {
        $this->import("concept,note,term:de,also:de,term:en\na,Alt,Bett,Betten,Bed\nb,,Sofa,,Couch\n");

        $this->import("Term:DE,CONCEPT,also:de,note,term:en,avoid:de\nBett,a,,,,Polsterbett\n")
            ->assertJsonPath('data.summary.updated', 1)
            ->assertJsonPath('data.summary.added', 0);

        $bett = $this->term('Bett');
        $this->assertSame(['Betten'], $bett->aliases);
        $this->assertSame(['Polsterbett'], $bett->forbidden);
        $this->assertSame('Alt', $bett->concept->note);
        $this->assertSame('Bed', $this->term('Bed')->preferred);
        $this->assertSame('Sofa', $this->term('Sofa')->preferred);
    }

    #[Test]
    public function a_header_tolerates_a_bom_case_and_locale_spellings(): void
    {
        $this->import("\xEF\xBB\xBFTERM:DE;Term:de_AT;also:de\nStuhl;Sessel;\"Sitz; Hocker\"\n");

        $this->assertSame('Stuhl', AiGlossaryTerm::query()->inGlossary($this->glossary->id)->where('locale', 'de')->value('preferred'));
        $this->assertSame('de-at', $this->term('Sessel')->locale);
        $this->assertSame(['Sitz', 'Hocker'], $this->term('Stuhl')->aliases);
    }

    #[Test]
    public function a_row_without_a_key_matches_on_the_default_language_term(): void
    {
        $this->import("term:en,term:de\nBed,Bett\n");
        $this->import("term:en,term:de,note\nBedstead,bett,Beds\n")
            ->assertJsonPath('data.summary.updated', 1);

        $this->assertSame('Bedstead', $this->term('bett')->concept->terms()->where('locale', 'en')->value('preferred'));
    }

    #[Test]
    public function an_unchanged_row_counts_as_unchanged_and_changes_nothing(): void
    {
        $csv = "concept,note,term:de,term:en\na,Beds,Bett,Bed\nb,Beds,Sofa,Couch\n";

        $this->import($csv)->assertJsonPath('data.summary.added', 2);
        $stamp = $this->term('Bett')->updated_at;

        $this->import($csv)
            ->assertJsonPath('data.summary.unchanged', 2)
            ->assertJsonPath('data.summary.updated', 0)
            ->assertJsonPath('data.summary.total_changes', 0);

        $this->assertEquals($stamp, $this->term('Bett')->updated_at);
    }

    #[Test]
    public function each_import_writes_one_audit_entry_with_its_actor_file_and_counts(): void
    {
        $this->import("concept,term:de,also:de\na,Bett,Alt\n");
        $this->import("concept,also:de,term:de,keep_exact\na,Neu,Bett,\nb,,Sofa,maybe\nc,,Stuhl,\n", 'Änderung 2026.csv');

        $entries = $this->auditEntries();

        $this->assertCount(2, $entries);
        $this->assertSame($this->user->id, $entries[1]->user_id);
        $this->assertSame(AiGlossary::class, $entries[1]->entity_type);
        $this->assertSame('Änderung 2026.csv', $entries[1]->metadata['filename']);
        $this->assertSame(['added' => 1, 'updated' => 1, 'unchanged' => 0, 'errors' => 1], $entries[1]->new_values);
    }

    #[Test]
    public function a_wording_held_by_another_concept_is_a_row_error_and_the_rest_applies(): void
    {
        $this->import("concept,term:de\nheld,Bett\n");

        $this->import("concept,term:de\nnew,Bett\nother,Sofa\n")
            ->assertJsonPath('data.summary.added', 1)
            ->assertJsonPath('data.errors.0.row', 2)
            ->assertJsonPath('data.errors.0.column', 'term:de')
            ->assertJsonPath('data.errors.0.message', fn (string $message) => str_contains($message, 'Bett') && str_contains($message, 'held'));

        $this->assertNull(AiGlossaryConcept::query()->where('key', 'new')->first());
    }

    #[Test]
    public function a_bad_value_is_a_row_error_naming_the_column_and_row(): void
    {
        $this->import("term:de,keep_exact,concept\nStuhl,perhaps,\nTisch,,bad key\nLampe,,\n")
            ->assertJsonPath('data.summary.added', 1)
            ->assertJsonPath('data.summary.total_success', 1)
            ->assertJsonPath('data.summary.total_errors', 2)
            ->assertJsonPath('data.errors.0', ['row' => 2, 'column' => 'keep_exact', 'message' => 'Use yes or no, not "perhaps".'])
            ->assertJsonPath('data.errors.1.column', 'concept')
            ->assertJsonPath('data.errors.1.row', 3);
    }

    #[Test]
    public function a_file_where_every_row_failed_reports_no_success(): void
    {
        $this->import("term:de,keep_exact\nBett,maybe\nSofa,nope\n")
            ->assertJsonPath('data.summary.total_success', 0)
            ->assertJsonPath('data.summary.added', 0)
            ->assertJsonPath('data.summary.total_errors', 2);

        $this->assertSame(0, $this->glossary->terms()->count());
    }

    #[Test]
    public function an_unknown_column_is_reported_once_and_does_not_fail_the_file(): void
    {
        $this->import("term:de,owner,term\nBett,Anna,x\nSofa,Ben,y\n")
            ->assertJsonPath('data.ignored_fields', ['owner', 'term'])
            ->assertJsonPath('data.summary.added', 2)
            ->assertJsonPath('data.summary.total_errors', 0);
    }

    #[Test]
    public function a_cell_past_the_last_column_is_a_row_error(): void
    {
        $this->import("term:de,note\nBett,Ein,Bett\n")
            ->assertJsonPath('data.summary.added', 0)
            ->assertJsonPath('data.errors.0.row', 2);
    }

    #[Test]
    public function a_locale_the_space_has_not_enabled_is_stored_and_warned_about_once(): void
    {
        $this->import("term:de,term:fr,term:it\nBett,Lit,Letto\nSofa,Canapé,\n")
            ->assertJsonPath('data.summary.added', 2)
            ->assertJsonPath('data.warnings.0.code', 'unused_locales')
            ->assertJsonPath('data.warnings.0.locales', ['fr', 'it'])
            ->assertJsonCount(1, 'data.warnings');

        $this->assertSame('fr', $this->term('Lit')->locale);
    }

    #[Test]
    public function within_a_file_the_first_key_and_the_first_default_term_win(): void
    {
        $this->import("concept,term:de,note\na,Bett,Eins\na,Sofa,Zwei\n,Tisch,Drei\n,tisch,Vier\n")
            ->assertJsonPath('data.summary.added', 2)
            ->assertJsonPath('data.errors.0.row', 3)
            ->assertJsonPath('data.errors.0.column', 'concept')
            ->assertJsonPath('data.errors.1.row', 5)
            ->assertJsonPath('data.errors.1.column', 'term:de');

        $this->assertSame('Eins', $this->term('Bett')->concept->note);
        $this->assertSame('Drei', $this->term('Tisch')->concept->note);
        $this->assertNull($this->glossary->terms()->where('preferred', 'Sofa')->first());
    }

    #[Test]
    public function an_old_file_with_a_bad_language_or_long_wording_loses_only_that_row(): void
    {
        $long = str_repeat('ä', 256);
        $response = $this->import(implode("\n", [
            'source_locale,term,case_sensitive,translation:de,translation:not valid',
            'en,cart,1,Warenkorb,',
            'en,pillow,0,Kissen,Oreiller',
            '!,sofa,0,,',
            "en,bed,0,{$long},",
            'en,lamp,0,Lampe,',
        ]));

        $response->assertOk();
        $this->assertSame([2, 3, 4], array_column($response->json('data.errors'), 'row'));
        $this->assertStringContainsString('not valid', $response->json('data.errors.0.message'));
        $this->assertSame(['cart', 'lamp'], array_column($response->json('data.successes'), 'term'));
        $this->assertFalse($this->term('cart')->concept->keep_exact, 'An old case_sensitive column is not read: case follows Keep exact.');
    }

    #[Test]
    public function a_tbx_section_without_a_language_is_a_row_error_and_the_rest_applies(): void
    {
        $tbx = <<<'XML'
        <tbx xmlns="urn:iso:std:iso:30042:ed-2"><text><body>
          <conceptEntry id="a"><langSec><termSec><term>nameless</term></termSec></langSec></conceptEntry>
          <conceptEntry id="b"><langSec xml:lang="de"><termSec><term>Bett</term></termSec></langSec></conceptEntry>
        </body></text></tbx>
        XML;

        $response = $this->import($tbx, 'glossary.tbx')->assertOk();

        $this->assertSame([1], array_column($response->json('data.errors'), 'row'));
        $this->assertStringContainsString('not a valid locale', $response->json('data.errors.0.message'));
        $this->assertSame('de', $this->term('Bett')->locale);
        $this->assertSame(1, AiGlossaryConcept::query()->count());
    }

    #[Test]
    public function an_old_format_header_keeps_importing_as_before(): void
    {
        $this->import("SOURCE_LOCALE,term,forbidden,translation:en\nde,Bett,Couch|Pritsche,Bed\n")
            ->assertJsonPath('data.summary.total_success', 1)
            ->assertJsonMissingPath('data.summary.added');

        $term = $this->term('Bett');
        $this->assertSame(['Couch', 'Pritsche'], $term->forbidden);
        $this->assertCount(0, $this->auditEntries());
    }

    #[Test]
    public function a_file_with_no_usable_columns_is_refused(): void
    {
        $this->post("{$this->base}/import", ['file' => UploadedFile::fake()->createWithContent('g.csv', "owner,price\nAnna,3\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function an_export_round_trips_with_no_changes_in_both_formats(): void
    {
        $this->import(implode("\n", [
            'concept,keep_exact,note,term:de,avoid:de,also:de,term:en,term:fr',
            'cloud-bed,yes,"Mehrzeilig'."\n".'Text",Wolkenbett,Schwebebett; Luft*,WB; "x",Cloud bed,Lit',
            ',no,,Altbett,,,,',
            ',no,,,Billigbett; Billig*,,,',
        ]));

        $this->glossaryRow($this->glossary, ['en' => 'Lamp', 'de' => 'Lampe', 'es' => 'Lámpara'], ['note' => 'Alt']);
        // A wording that looks like a number, a flag and a list separator in one wording.
        $this->glossaryRow($this->glossary, ['de' => ['use' => '1.50', 'also' => ['TRUE', 'a;b']]]);

        $snapshot = fn () => AiGlossaryTerm::query()->inGlossary($this->glossary->id)->orderBy('id')->get()->map->only(['id', 'locale', 'preferred', 'forbidden', 'aliases', 'concept_id'])->all();
        $terms = $snapshot();

        foreach (['csv', 'xlsx'] as $format) {
            $body = $this->exportBody($format);

            $this->import($body, "glossary.{$format}")
                ->assertJsonPath('data.summary.total_errors', 0)
                ->assertJsonPath('data.summary.added', 0)
                ->assertJsonPath('data.summary.updated', 0)
                ->assertJsonPath('data.summary.unchanged', 5);
        }

        $this->assertSame($terms, $snapshot());
    }

    #[Test]
    public function an_export_writes_one_row_per_concept_with_the_default_language_first(): void
    {
        $this->import("concept,term:en,term:de,term:fr\nb,Couch,Sofa,\na,Bed,Bett,Lit\n");
        $this->glossaryRow($this->glossary, ['en' => 'Lamp']);

        [$headers, $rows] = $this->parseCsv($this->exportBody('csv'));

        $this->assertSame(['concept', 'keep_exact', 'note', 'term:de', 'avoid:de', 'also:de', 'term:en'], \array_slice($headers, 0, 7));
        $this->assertSame('term:fr', $headers[\count($headers) - 3]);
        $this->assertCount(3, $rows);
        $this->assertSame(['Bett', 'Sofa', ''], array_column($rows, 'term:de'));
        $this->assertSame(['a', 'b', ''], array_column($rows, 'concept'));
        $this->assertSame('Lamp', $rows[2]['term:en']);
    }

    #[Test]
    public function a_key_in_the_sheet_is_given_to_a_row_that_has_none(): void
    {
        $this->import("term:de\nBett\n");
        $this->import("concept,term:de\nbed,Bett\n")->assertJsonPath('data.summary.updated', 1);

        $this->assertSame('bed', $this->term('Bett')->concept->key);
    }

    #[Test]
    public function injection_values_export_safely_and_read_back_exactly(): void
    {
        $values = ['=SUM(A1)', '+1', '-x', '@me', "'=already", "''+twice", "'plain"];

        foreach ($values as $index => $value) {
            $this->glossaryRow($this->glossary, ['de' => ['use' => "Wort {$index}", 'also' => [$value]]], ['note' => $value]);
        }

        foreach (['csv', 'xlsx'] as $format) {
            $body = $this->exportBody($format);
            $path = "{$this->tempDir}/export.{$format}";
            file_put_contents($path, $body);
            $cells = IOFactory::load($path)->getSheet(0)->toArray(null, false, false, false);

            foreach ($cells as $row) {
                foreach ($row as $cell) {
                    $this->assertDoesNotMatchRegularExpression('/^[=+\-@]/', (string) $cell);
                }
            }

            $this->import($body, "g.{$format}")
                ->assertJsonPath('data.summary.unchanged', \count($values))
                ->assertJsonPath('data.summary.total_errors', 0);
        }

        foreach ($values as $index => $value) {
            $this->assertSame($value, $this->term("Wort {$index}")->concept->note);
            $this->assertSame([$value], $this->term("Wort {$index}")->aliases);
        }
    }

    #[Test]
    public function a_template_names_the_space_languages_and_holds_one_fake_row(): void
    {
        [$headers, $rows] = $this->parseCsv($this->exportBody('csv', '&template=1'));

        $this->assertSame('term:de', $headers[3]);
        $this->assertContains('term:en', $headers);
        $this->assertCount(1, $rows);
        $this->assertSame('example-concept', $rows[0]['concept']);
        $this->assertSame(0, $this->glossary->terms()->count());

        $this->import($this->exportBody('csv', '&template=1'))
            ->assertJsonPath('data.summary.added', 1)
            ->assertJsonPath('data.summary.total_errors', 0);

        $this->getJson("{$this->base}/export?format=tbx&template=1")->assertUnprocessable();
    }

    #[Test]
    public function an_xlsx_template_has_a_help_sheet(): void
    {
        $path = "{$this->tempDir}/template.xlsx";
        file_put_contents($path, $this->exportBody('xlsx', '&template=1'));
        $book = IOFactory::load($path);

        $this->assertSame(['Glossary', 'Help'], $book->getSheetNames());
        $this->assertSame('concept', $book->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('keep_exact', $book->getSheet(1)->getCell('A3')->getValue());
        $this->assertSame('term:<language>', $book->getSheet(1)->getCell('A5')->getValue());
    }

    #[Test]
    public function a_team_template_uses_the_glossary_locales_and_a_row_matches_on_the_first_term_column(): void
    {
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $this->user, 'owner');
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes($team), 'name' => 'Brand']);
        $base = "/mgmt/v1/teams/{$team->id}/ai/glossaries/{$glossary->id}";
        $post = fn (string $csv) => $this->post("{$base}/import", ['file' => UploadedFile::fake()->createWithContent('g.csv', $csv)], ['Accept' => 'application/json'])->assertOk();

        $this->assertStringContainsString('term:'.config('app.locale'), $this->get("{$base}/export?format=csv&template=1")->assertOk()->streamedContent());

        $post("term:fr,term:en\nLit,Bed\n")->assertJsonPath('data.warnings', []);
        $post("term:fr,term:en\nlit,Bedstead\n")->assertJsonPath('data.summary.updated', 1);

        $this->assertStringContainsString('term:en', $this->get("{$base}/export?format=csv&template=1")->assertOk()->streamedContent());
        $this->assertSame('Bedstead', $glossary->terms()->where('locale', 'en')->value('preferred'));
    }

    #[Test]
    public function a_streaming_import_reports_progress_and_ends_with_the_result(): void
    {
        $rows = ['concept,term:de,keep_exact'];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = "c{$i},Wort {$i},".($i === 60 ? 'maybe' : 'no');
        }

        $response = $this->post("{$this->base}/import", ['file' => UploadedFile::fake()->createWithContent('g.csv', implode("\n", $rows))], ['Accept' => 'text/event-stream'])->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $events = [];
        foreach (preg_split('/\R\R/', trim($response->streamedContent())) as $block) {
            if (preg_match('/^data: (.*)$/m', $block, $data) === 1) {
                $events[] = json_decode($data[1], true);
            }
        }

        $progress = array_map(static fn (array $event) => json_decode($event['message'], true), array_values(array_filter($events, static fn (array $event) => ($event['type'] ?? null) === 'status')));
        $this->assertContains(['processed' => 50, 'total' => 120], $progress);
        $this->assertSame(['processed' => 120, 'total' => 120], end($progress));

        $done = collect($events)->firstWhere('type', 'done');
        $this->assertSame(119, $done['data']['summary']['added']);
        $this->assertSame('keep_exact', $done['data']['errors'][0]['column']);

        $this->assertSame(['added' => 119, 'updated' => 0, 'unchanged' => 0, 'errors' => 1], $this->auditEntries()->sole()->new_values);
    }

    #[Test]
    public function a_real_xlsx_upload_reads_typed_cells_and_only_the_first_sheet(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['term:de', 'keep_exact', 'also:de'], ['Bett', true, 2024], [null, null, null], ['Sofa', false, 1.5]]);
        $book->createSheet()->setTitle('Help')->setCellValue('A1', 'term:de');
        $book->setActiveSheetIndex(1);
        $path = "{$this->tempDir}/upload.xlsx";
        (new Xlsx($book))->save($path);

        $this->import((string) file_get_contents($path), 'upload.xlsx')->assertJsonPath('data.summary.added', 2);

        $this->assertTrue($this->term('Bett')->concept->keep_exact);
        $this->assertSame(['2024'], $this->term('Bett')->aliases);
        $this->assertSame(['1.5'], $this->term('Sofa')->aliases);
    }

    #[Test]
    public function tbx_preferred_and_deprecated_terms_are_use_and_avoid_and_read_back_unchanged(): void
    {
        $tbx = <<<'XML'
        <tbx xmlns="urn:iso:std:iso:30042:ed-2"><text><body>
          <conceptEntry id="a">
            <descrip type="definition">Nur Serie X</descrip>
            <langSec xml:lang="de"><termSec><term>Wolkenbett</term><termNote type="administrativeStatus">preferredTerm-admn-sts</termNote></termSec><termSec><term>Luftbett</term><termNote type="administrativeStatus">deprecatedTerm-admn-sts</termNote></termSec></langSec>
            <langSec xml:lang="en"><termSec><term>Cloud bed</term></termSec><termSec><term>air mattress</term><termNote type="administrativeStatus">supersededTerm-admn-sts</termNote></termSec></langSec>
          </conceptEntry>
          <conceptEntry id="b">
            <langSec xml:lang="de"><termSec><term>Billigbett</term><termNote type="administrativeStatus">deprecatedTerm-admn-sts</termNote></termSec></langSec>
          </conceptEntry>
        </body></text></tbx>
        XML;

        $this->import($tbx, 'g.tbx')->assertJsonPath('data.summary.total_success', 2)->assertJsonPath('data.summary.total_errors', 0);

        $de = $this->term('Wolkenbett');
        $this->assertSame(['Luftbett'], $de->forbidden);
        $this->assertSame($de->concept_id, $this->term('Cloud bed')->concept_id);
        $this->assertSame('Nur Serie X', $de->concept->note);
        $this->assertSame(['air mattress'], $this->term('Cloud bed')->forbidden);
        $this->assertNull($this->term('Billigbett')->preferred);

        $snapshot = fn () => AiGlossaryTerm::query()->inGlossary($this->glossary->id)->orderBy('id')->get()->map->only(['id', 'locale', 'preferred', 'forbidden', 'aliases', 'concept_id'])->all();
        $terms = $snapshot();

        $export = $this->get("{$this->base}/export?format=tbx")->assertOk()->getContent();
        $this->import($export, 'g.tbx')->assertJsonPath('data.summary.total_errors', 0);

        $this->assertSame($terms, $snapshot());
    }

    #[Test]
    public function a_second_preferred_tbx_term_stays_on_the_row_as_also_written_as(): void
    {
        $this->import(<<<'XML'
        <tbx xmlns="urn:iso:std:iso:30042:ed-2"><text><body><conceptEntry id="a">
          <langSec xml:lang="de">
            <termSec><term>Wolkenbett</term><termNote type="administrativeStatus">preferredTerm-admn-sts</termNote></termSec>
            <termSec><term>Traumbett</term><termNote type="administrativeStatus">preferredTerm-admn-sts</termNote></termSec>
            <termSec><term>WB</term><termNote type="administrativeStatus">admittedTerm-admn-sts</termNote></termSec>
          </langSec>
        </conceptEntry></body></text></tbx>
        XML, 'g.tbx')->assertJsonPath('data.summary.total_success', 1);

        $this->assertSame(['Traumbett', 'WB'], $this->term('Wolkenbett')->aliases);
    }

    #[Test]
    public function tbx_and_older_files_refuse_a_new_or_changed_long_note_and_keep_an_unchanged_one(): void
    {
        $tbx = static fn (string $note, string $wording): string => <<<XML
        <tbx xmlns="urn:iso:std:iso:30042:ed-2"><text><body><conceptEntry id="a">
            <descrip type="definition">{$note}</descrip>
            <langSec xml:lang="de"><termSec><term>{$wording}</term></termSec></langSec>
        </conceptEntry></body></text></tbx>
        XML;
        $older = static fn (string $note, string $wording): string => "source_locale,term,forbidden,note\nde,{$wording},,{$note}\n";
        $long = str_repeat('x', 5000);
        $stored = str_repeat('y', 201);

        foreach ([[$tbx, 'g.tbx', 'Wolkenbett'], [$older, 'g.csv', 'Traumbett']] as [$file, $name, $wording]) {
            $this->import($file($long, $wording), $name)
                ->assertJsonPath('data.summary.total_success', 0)
                ->assertJsonPath('data.errors.0.message', 'The note is longer than 200 characters.');
            $this->assertNull(AiGlossaryTerm::query()->where('preferred', $wording)->first());
        }

        $id = $this->glossaryRow($this->glossary, ['de' => 'Sofa']);
        AiGlossaryConcept::query()->whereKey($id)->update(['note' => $stored]);

        foreach ([[$tbx, 'g.tbx'], [$older, 'g.csv']] as [$file, $name]) {
            $this->import($file($long, 'Sofa'), $name)->assertJsonPath('data.summary.total_errors', 1);
            $this->assertSame($stored, $this->term('Sofa')->concept->note);

            $this->import($file($stored, 'Sofa'), $name)->assertJsonPath('data.summary.total_errors', 0);
            $this->assertSame($stored, $this->term('Sofa')->concept->note);
        }

        $this->import($this->get("{$this->base}/export?format=tbx")->assertOk()->getContent(), 'g.tbx')->assertJsonPath('data.summary.total_errors', 0);
        $this->assertSame($stored, $this->term('Sofa')->concept->note);
    }

    #[Test]
    public function an_older_file_writes_through_the_row_and_updates_by_wording(): void
    {
        $this->glossaryRow($this->glossary, ['en' => 'Bed', 'de' => 'Bett'], ['key' => 'bed']);
        $csv = "source_locale,term,translation:de,forbidden,do_not_translate,note\nen,Bed,Bettchen,cot|crib,yes,Beds\nen,Lamp,Lampe,,,\n,Nameless,,,,\n";

        $this->import($csv)->assertJsonPath('data.summary.total_success', 2)->assertJsonPath('data.errors.0.row', 3);

        $bed = AiGlossaryConcept::query()->where('key', 'bed')->firstOrFail();
        $this->assertSame(2, $bed->terms()->count(), 'The row it found stays one row.');
        $this->assertTrue($bed->keep_exact);
        $this->assertSame('Beds', $bed->note);
        $this->assertSame(['cot', 'crib'], $this->term('Bed')->forbidden);
        $this->assertSame($bed->id, $this->term('Bettchen')->concept_id, 'The file names the German wording, so it changes on that row.');
        $this->assertSame($this->term('Lamp')->concept_id, $this->term('Lampe')->concept_id);
    }

    #[Test]
    public function an_older_file_or_tbx_never_overwrites_wording_it_did_not_name(): void
    {
        $this->import("source_locale,term,translation:en\nde,Liege,bed\nde,Bett,bed\n")
            ->assertJsonPath('data.summary.total_success', 1)
            ->assertJsonPath('data.errors.0.row', 2);
        $this->assertSame($this->term('Liege')->concept_id, $this->term('bed')->concept_id);
        $this->assertSame(1, AiGlossaryConcept::query()->count());

        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Kunde', 'avoid' => ['Kundschaft']], 'en' => 'Customer']);
        $this->import(<<<'XML'
        <tbx xmlns="urn:iso:std:iso:30042:ed-2"><text><body><conceptEntry id="a">
          <langSec xml:lang="de"><termSec><term>Klient</term></termSec></langSec>
          <langSec xml:lang="en"><termSec><term>Customer</term></termSec></langSec>
        </conceptEntry></body></text></tbx>
        XML, 'g.tbx')->assertJsonPath('data.summary.total_success', 0)->assertJsonPath('data.errors.0.row', 1);
        $this->assertSame($this->term('Kunde')->concept_id, $this->term('Customer')->concept_id);
        $this->assertSame(['Kundschaft'], $this->term('Kunde')->forbidden);

        // A row with nothing in the file's first language has nothing to lose, so it gains it.
        $lamp = $this->glossaryRow($this->glossary, ['en' => 'lamp']);
        $this->import("source_locale,term,translation:en\nde,Lampe,lamp\n")->assertJsonPath('data.summary.total_errors', 0);
        $this->assertSame($lamp, $this->term('Lampe')->concept_id);
        $this->assertSame($lamp, $this->term('lamp')->concept_id);
    }

    #[Test]
    public function an_export_scans_the_glossary_once_however_many_pages_it_holds(): void
    {
        for ($batch = 0; $batch < 3; $batch++) {
            GlossaryConcepts::transaction($this->glossary, function (GlossaryConcepts $concepts) use ($batch) {
                foreach (range(1, 250) as $index) {
                    $concepts->create(['languages' => ['de' => ['use' => "Wort {$batch}-{$index}"]]]);
                }
            });
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = iterator_to_array((new GlossarySheetExport($this->glossary, $this->space))->rows(), false);
        $scans = array_filter(DB::getQueryLog(), static fn (array $query) => str_contains($query['query'], 'inner join'));
        DB::disableQueryLog();

        $this->assertCount(750, $rows);
        $this->assertCount(1, $scans);
    }
}
