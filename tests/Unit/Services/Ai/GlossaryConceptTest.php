<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Knowledge\GlossaryBrief;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\LegacyGlossaryRows;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Tools\LookupGlossaryTool;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GlossaryConceptTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, string, string|null}>
     */
    public static function localeCases(): array
    {
        return [
            'exact' => [['de-at', 'de-de'], 'de-DE', 'de-de'],
            'exact beats base' => [['de', 'de-at'], 'de-AT', 'de-at'],
            'normalized spelling' => [['de_AT', 'de-de'], 'DE-at', 'de-at'],
            'base serves a region' => [['de-at', 'de'], 'de-CH', 'de'],
            'sibling never serves a region' => [['de-at'], 'de-DE', null],
            'region never serves its base' => [['de-at', 'de-de'], 'de', null],
            'other language' => [['fr'], 'de', null],
        ];
    }

    /**
     * @param  list<string>  $locales
     */
    #[Test]
    #[DataProvider('localeCases')]
    public function it_selects_the_exact_locale_then_the_base_language(array $locales, string $locale, ?string $expected): void
    {
        $concept = GlossaryConcept::of(array_fill_keys($locales, ['use' => 'x']));

        $this->assertSame($expected, $concept->languageFor($locale)?->locale);
    }

    #[Test]
    public function the_prompt_lookup_and_checker_agree_on_the_language_that_counts_for_a_region(): void
    {
        $concepts = [GlossaryConcept::of([
            'de' => ['use' => 'Aufzug'],
            'en' => ['use' => 'elevator', 'avoid' => ['lift']],
            'en-gb' => ['use' => 'lift'],
        ], id: 'c1')];
        $policy = PolicySnapshot::fromConcepts($concepts);
        $checker = new GlossaryChecker;

        // British English has its own wording: the base language's Avoid does not reach it.
        $this->assertStringContainsString('- "Aufzug": write "lift" in en-GB', (string) GlossaryBrief::render($policy->activate('Der Aufzug', 'de', 'en-GB'), 'de', 'en-GB'));
        $this->assertSame([], $checker->check('Der Aufzug', 'The lift', 'de', 'en-GB', $concepts));
        $lookup = (new LookupGlossaryTool(fn () => $policy))->execute(['text' => 'Der Aufzug', 'source_locale' => 'de', 'target_locale' => 'en-GB'])['terms'][0];
        $this->assertSame('lift', $lookup['required']);
        $this->assertArrayNotHasKey('forbidden', $lookup);

        // The base language still bans it, and the replacement is its own Use.
        $this->assertStringContainsString('- "Aufzug": write "elevator" in en; never use "lift"', (string) GlossaryBrief::render($policy->activate('Der Aufzug', 'de', 'en'), 'de', 'en'));
        $this->assertSame(
            [['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'elevator', 'expected' => 'elevator', 'found' => 'lift']],
            $checker->check('Der Aufzug', 'The elevator, not the lift', 'de', 'en', $concepts),
        );

        // A region without its own language uses the base language, Avoid included.
        $this->assertSame(
            [['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'elevator', 'expected' => 'elevator', 'found' => 'lift']],
            $checker->check('Der Aufzug', 'The elevator, not the lift', 'de', 'en-US', $concepts),
        );
    }

    #[Test]
    public function a_sibling_region_supplies_nothing_required_and_a_regional_adaptation_checks_its_wording(): void
    {
        $concepts = [GlossaryConcept::of(['de-at' => ['use' => 'Jänner', 'avoid' => ['Jaenner']], 'de-de' => ['use' => 'Januar']])];
        $checker = new GlossaryChecker;

        $this->assertSame(
            [['type' => GlossaryChecker::MISSING_TERM, 'term' => 'Jänner', 'expected' => 'Januar']],
            $checker->check('Im Jänner', 'Im Jänner', 'de-AT', 'de-DE', $concepts),
        );
        $this->assertSame([], $checker->check('Im Jänner', 'Im Januar', 'de-AT', 'de-DE', $concepts));
        $this->assertSame([], $checker->check('Im Jänner', 'Etwas', 'de-AT', 'de-CH', $concepts), 'No wording for this region: a sibling does not stand in.');
        $this->assertSame([], $checker->check('Im Jänner', 'Im Jaenner', 'de-CH', 'de-CH', $concepts), 'No language counts for this region.');

        // Same locale: no adaptation, only Avoid still counts.
        $this->assertSame([], $checker->check('Im Jänner', 'Im Jänner', 'de_AT', 'de-at', $concepts));
        $this->assertSame(
            [['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'Jänner', 'expected' => 'Jänner', 'found' => 'Jaenner']],
            $checker->check('Im Jänner', 'Im Jaenner', 'de-AT', 'de-AT', $concepts),
        );
    }

    #[Test]
    public function the_lookup_answers_with_keep_exact_and_the_note(): void
    {
        $concepts = [
            GlossaryConcept::of(['en' => ['use' => 'Night Shift', 'also' => ['late shift']], 'de' => ['use' => 'Nachtschicht']], keepExact: true),
            GlossaryConcept::of(['en' => ['use' => 'CloudBed']], keepExact: true),
            GlossaryConcept::of(['en' => ['use' => 'Frame']], note: str_repeat('Long older note. ', 40)),
        ];

        $found = (new LookupGlossaryTool(fn () => $concepts))->execute(['text' => 'late shift, CloudBed, Frame', 'source_locale' => 'en', 'target_locale' => 'de'])['terms'];
        $byTerm = array_column($found, null, 'term');

        $this->assertSame('Nachtschicht', $byTerm['Night Shift']['required'], 'Also written as counts as the term.');
        $this->assertArrayNotHasKey('do_not_translate', $byTerm['Night Shift']);
        $this->assertTrue($byTerm['CloudBed']['do_not_translate']);
        $this->assertArrayNotHasKey('required', $byTerm['CloudBed']);
        $this->assertSame('"CloudBed": keep exactly as "CloudBed"', $byTerm['CloudBed']['instruction']);
        $this->assertSame(str_repeat('Long older note. ', 40), $byTerm['Frame']['note'], 'A longer older note stays readable.');
    }

    #[Test]
    public function an_older_row_reads_as_one_concept_with_a_language_per_translation(): void
    {
        [$unlinked] = LegacyGlossaryRows::concepts([[
            'id' => 't1', 'glossary_id' => 'g1', 'source_locale' => 'en', 'term' => 'cart', 'note' => 'Shop only',
            'translations' => ['de_AT' => 'Sackerl', 'de' => ' '], 'forbidden' => ['basket'], 'source_aliases' => ['carts'],
            'do_not_translate' => true, 'case_sensitive' => true, 'concept_id' => null,
        ]]);

        $this->assertSame('t1', $unlinked['id']);
        $this->assertTrue($unlinked['keep_exact']);
        $this->assertSame('Shop only', $unlinked['note']);
        $this->assertSame([
            'en' => ['use' => 'cart', 'avoid' => ['basket'], 'also' => ['carts']],
            'de-at' => ['use' => 'Sackerl', 'avoid' => ['basket'], 'also' => []],
        ], $unlinked['languages'], 'Avoid of a row without a concept counted in every output language.');
    }

    #[Test]
    public function older_linked_rows_become_one_concept_and_no_wording_goes_missing(): void
    {
        $rows = [
            ['id' => 'a', 'glossary_id' => 'g', 'concept_id' => 'c', 'source_locale' => 'de', 'term' => 'Tasche', 'translations' => ['en' => 'bag', 'fr' => 'sac'], 'forbidden' => ['Beutel']],
            ['id' => 'b', 'glossary_id' => 'g', 'concept_id' => 'c', 'source_locale' => 'en', 'term' => 'pouch', 'translations' => ['de' => 'Tasche'], 'forbidden' => []],
            ['id' => 'x', 'glossary_id' => 'g', 'concept_id' => null, 'source_locale' => 'de', 'term' => 'Billig', 'translations' => ['en' => 'value'], 'forbidden' => ['Billig', 'Billig*']],
        ];

        $concepts = array_column(LegacyGlossaryRows::concepts($rows), null, 'id');

        $this->assertSame([
            'de' => ['use' => 'Tasche', 'avoid' => ['Beutel'], 'also' => []],
            'en' => ['use' => 'pouch', 'avoid' => [], 'also' => ['bag']],
            'fr' => ['use' => 'sac', 'avoid' => [], 'also' => []],
        ], $concepts['c']['languages'], 'A language keeps its own Use, and a translation that disagrees stays as Also written as.');

        $this->assertSame([
            'de' => ['use' => null, 'avoid' => ['Billig', 'Billig*'], 'also' => []],
            'en' => ['use' => 'value', 'avoid' => [], 'also' => []],
        ], $concepts['x']['languages'], 'A ban names wording of its own language only.');
    }

    #[Test]
    public function a_snapshot_from_before_rows_became_concepts_is_refused(): void
    {
        $this->expectException(LogicException::class);

        PolicySnapshot::fromPayload(['version' => 1, 'terms' => [['term' => 'cart', 'source_locale' => 'en']], 'documents' => []], 'snapshot', 'fingerprint');
    }

    #[Test]
    public function a_row_with_nothing_for_the_output_language_is_not_a_rule_to_write_the_source_wording(): void
    {
        $concepts = [
            GlossaryConcept::of(['en' => ['use' => 'cart']], id: 'plain'),
            GlossaryConcept::of(['en' => ['use' => 'pillow']], note: 'Sleep line', id: 'noted'),
            GlossaryConcept::of(['en' => ['use' => 'Acme']], keepExact: true, id: 'name'),
        ];
        $policy = PolicySnapshot::fromConcepts($concepts);

        $this->assertNull(GlossaryBrief::render($policy->activate('a cart', 'en', 'de'), 'en', 'de'), 'Nothing to say, so no line and no heading.');

        $brief = (string) GlossaryBrief::render($policy->activate('a cart, a pillow, Acme', 'en', 'de'), 'en', 'de');
        $this->assertStringNotContainsString('"cart"', $brief);
        $this->assertStringContainsString('- "pillow": translate naturally; note: Sleep line', $brief);
        $this->assertStringContainsString('- "Acme": keep exactly as "Acme"', $brief);

        $lookup = (new LookupGlossaryTool(fn () => $policy))->execute(['text' => 'a cart', 'source_locale' => 'en', 'target_locale' => 'de'])['terms'][0];
        $this->assertSame('"cart": translate naturally', $lookup['instruction']);

        // A base language serves a region, so that is still wording to write.
        $this->assertStringContainsString('- "cart"', (string) GlossaryBrief::render($policy->activate('a cart', 'en', 'en-GB'), 'en', 'en-GB'));
    }

    #[Test]
    public function rows_that_read_the_same_are_told_once(): void
    {
        $twins = [
            GlossaryConcept::of(['en' => ['use' => 'cart'], 'de' => ['use' => 'Warenkorb']], id: 'a'),
            GlossaryConcept::of(['en' => ['use' => 'cart'], 'de' => ['use' => 'Warenkorb']], id: 'b'),
        ];

        $brief = (string) GlossaryBrief::render(PolicySnapshot::fromConcepts($twins)->activate('a cart', 'en', 'de'), 'en', 'de');

        $this->assertSame(1, substr_count($brief, '- "cart": write "Warenkorb" in de'));
    }
}
