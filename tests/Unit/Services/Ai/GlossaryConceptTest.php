<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Knowledge\GlossaryBrief;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\LegacyGlossaryRows;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Tools\LookupGlossaryTool;
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

    /**
     * @param  list<array<string, mixed>>  $terms
     */
    private function frozen(array $terms): PolicySnapshot
    {
        return PolicySnapshot::fromPayload(['version' => 1, 'terms' => $terms, 'documents' => []], 'snapshot', 'fingerprint');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function frozenRow(string $term, string $locale, array $fields = []): array
    {
        return ['term' => $term, 'source_locale' => $locale, 'translations' => [], 'case_sensitive' => false, 'do_not_translate' => false, 'forbidden' => [], 'note' => null, ...$fields];
    }

    #[Test]
    public function a_frozen_row_keeps_its_own_case_and_its_avoid_counts_in_every_output_language(): void
    {
        $policy = $this->frozen([$this->frozenRow('US', 'en', ['case_sensitive' => true, 'forbidden' => ['basket']])]);

        $this->assertSame([], $policy->activate('Tell us more', 'en', 'de'), 'A case-sensitive row is not found in other case.');
        $this->assertSame(
            [['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'US', 'found' => 'basket']],
            (new GlossaryChecker)->check('Tell us more', 'Mein basket', 'en', 'de', $policy->concepts),
        );
        $this->assertSame([], (new GlossaryChecker)->check('Tell us more', 'Mein BASKET', 'en', 'de', $policy->concepts), 'Case holds for its Avoid too.');
    }

    #[Test]
    public function a_frozen_ban_counts_in_its_own_language_and_a_linked_row_answers_for_itself(): void
    {
        $policy = $this->frozen([
            $this->frozenRow('Billig', 'de', ['forbidden' => ['Billig', 'Billig*']]),
            $this->frozenRow('cart', 'en', ['forbidden' => ['basket'], 'concept_id' => 'c1', 'id' => 't1', 'note' => 'Shop', 'translations' => ['de' => 'Warenkorb']]),
            $this->frozenRow('Warenkorb', 'de', ['forbidden' => ['Korb'], 'concept_id' => 'c1', 'id' => 't2']),
        ]);
        $checker = new GlossaryChecker;

        $found = static fn (array $findings) => array_column($findings, 'found');

        $this->assertSame(['Billigware'], $found($checker->check('', 'Billigware', 'de', 'de', $policy->concepts)));
        $this->assertSame([], $checker->check('', 'Billigware', 'de', 'en', $policy->concepts), 'A ban is per language.');
        $this->assertSame([], $checker->check('', 'a basket', 'en', 'de', $policy->concepts), 'A linked row counts in its own language only.');
        $this->assertSame(['Korb'], $found($checker->check('', 'ein Korb', 'en', 'de', $policy->concepts)));
        $this->assertSame(['basket'], $found($checker->check('', 'a basket', 'de', 'en-GB', $policy->concepts)), 'The same language in another region counts.');

        [$hit] = $policy->activate('Your cart', 'en', 'de');
        $this->assertSame(
            '"cart": write "Warenkorb" in de; never use "Korb"; note: Shop',
            GlossaryBrief::instruction(GlossaryBrief::rule($hit, 'en', 'de')),
            'The brief reads the linked language, as it did.',
        );
    }

    #[Test]
    public function a_frozen_row_finds_in_a_sibling_region_and_its_translations_stay_one_way(): void
    {
        $policy = $this->frozen([$this->frozenRow('Lift', 'en-GB', ['translations' => ['de' => 'Aufzug']])]);

        $this->assertSame(['Lift'], array_map(static fn (array $hit) => $hit['wording']->name(), $policy->activate('The Lift', 'en-US', 'de')), 'A row without a concept matched within its language.');
        $this->assertSame([], $policy->activate('Der Aufzug', 'de', 'en'), 'A translation names what to write. It is no source wording.');

        $current = PolicySnapshot::fromConcepts([GlossaryConcept::of(['en-gb' => ['use' => 'Lift'], 'de' => ['use' => 'Aufzug']])]);
        $this->assertSame([], $current->activate('The Lift', 'en-US', 'de'), 'A current row keeps the exact or base rule.');
        $this->assertCount(1, $current->activate('Der Aufzug', 'de', 'en-GB'));
    }

    #[Test]
    public function a_frozen_row_survives_being_frozen_again_into_a_run(): void
    {
        $row = $this->frozenRow('US', 'en', ['case_sensitive' => true, 'forbidden' => ['basket'], 'id' => 't1']);
        [$concept] = $this->frozen([$row])->concepts;
        [$again] = GlossaryConcept::listFromFrozen([$concept->toArray()]);

        $this->assertSame($row, $again->toArray());
        $this->assertTrue($again->caseSensitive);
        $this->assertCount(1, (new GlossaryChecker)->check('', 'basket', 'en', 'de', [$again]));
    }

    #[Test]
    public function a_frozen_snapshot_names_findings_as_they_were_named(): void
    {
        $policy = $this->frozen([
            $this->frozenRow('cart', 'en', ['translations' => ['de' => 'Warenkorb'], 'forbidden' => ['Korb'], 'id' => 't1']),
        ]);

        $this->assertSame(
            [['type' => GlossaryChecker::MISSING_TERM, 'term' => 'cart', 'expected' => 'Warenkorb'], ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'cart', 'expected' => 'Warenkorb', 'found' => 'Korb']],
            (new GlossaryChecker)->check('Your cart', 'Ihr Korb', 'en', 'de', $policy->concepts),
            'An override names a finding by its identity, so that stays too.',
        );
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

    #[Test]
    public function an_older_row_keeps_its_case_setting_where_it_differs_from_keep_exact(): void
    {
        $cases = static fn (array $row) => LegacyGlossaryRows::concepts([['id' => 'r', 'source_locale' => 'en', 'term' => 'cart', ...$row]])[0]['case_sensitive'];

        $this->assertTrue($cases(['case_sensitive' => true]));
        $this->assertFalse($cases(['do_not_translate' => true]));
        $this->assertNull($cases(['case_sensitive' => true, 'do_not_translate' => true]));
        $this->assertNull($cases([]));

        $this->assertTrue(GlossaryConcept::of(['en' => ['use' => 'x']], keepExact: true)->caseSensitive);
        $this->assertFalse(GlossaryConcept::of(['en' => ['use' => 'x']], keepExact: true, caseSensitive: false)->caseSensitive);
    }
}
