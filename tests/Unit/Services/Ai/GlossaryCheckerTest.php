<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GlossaryCheckerTest extends TestCase
{
    #[Test]
    public function a_ban_applies_to_its_language_and_has_no_replacement(): void
    {
        $terms = [GlossaryConcept::of(['de' => ['avoid' => ['Gift']]])];
        $checker = new GlossaryChecker;

        $this->assertSame([], $checker->check('Geschenk', 'A gift.', 'de', 'en', $terms));
        $this->assertSame([
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'Gift', 'found' => 'Gift'],
        ], $checker->check('Gift', 'Gift', 'de', 'de', $terms));
    }

    #[Test]
    public function a_ban_never_produces_required_wording(): void
    {
        $terms = [GlossaryConcept::of(['de' => ['avoid' => ['Billig']]], keepExact: true)];

        $this->assertSame([], (new GlossaryChecker)->check('Billig', 'Good value', 'de', 'en', $terms));
    }

    #[Test]
    public function the_row_use_in_the_output_language_is_the_replacement_for_avoid_wording(): void
    {
        $terms = [GlossaryConcept::of(['de' => ['use' => 'Stuhl'], 'en' => ['use' => 'chair', 'avoid' => ['stool']]], id: 'c1')];

        $this->assertSame(
            [['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'chair', 'expected' => 'chair', 'found' => 'stool']],
            (new GlossaryChecker)->check('Ein Stuhl', 'A chair and a stool.', 'de', 'en', $terms),
        );
    }

    #[Test]
    public function a_protected_name_is_fixed_per_language(): void
    {
        $terms = [GlossaryConcept::of(['en' => ['use' => 'Night Shift'], 'de' => ['use' => 'Nachtschicht']], keepExact: true), GlossaryConcept::of(['en' => ['use' => 'CloudBed']], keepExact: true)];
        $checker = new GlossaryChecker;

        $this->assertSame([], $checker->check('Night Shift and CloudBed', 'Nachtschicht und CloudBed', 'en', 'de', $terms));
        $this->assertSame(
            [['type' => GlossaryChecker::TRANSLATED_DNT, 'term' => 'Night Shift', 'expected' => 'Nachtschicht']],
            $checker->check('Night Shift', 'Night Shift', 'en', 'de', $terms),
            'The source wording is wrong where the language has its own.',
        );
        $this->assertSame(
            [['type' => GlossaryChecker::TRANSLATED_DNT, 'term' => 'CloudBed', 'expected' => 'CloudBed']],
            $checker->check('CloudBed', 'Wolkenbett', 'en', 'de', $terms),
            'Without its own wording the language keeps the source wording.',
        );
        $this->assertSame([], $checker->check('Night Shift', 'Night Shift', 'en', 'en', $terms), 'Within one locale the term keeps its own wording.');
    }

    #[Test]
    public function also_written_as_counts_as_the_term_for_required_and_keep_exact_checks(): void
    {
        $terms = [
            GlossaryConcept::of(['de' => ['use' => 'Berater', 'also' => ['Beratern']], 'en' => ['use' => 'advisor']]),
            GlossaryConcept::of(['de' => ['use' => 'CloudBed', 'also' => ['Wolkenbett']]], keepExact: true),
        ];

        $this->assertSame([
            ['type' => GlossaryChecker::MISSING_TERM, 'term' => 'Berater', 'expected' => 'advisor'],
            ['type' => GlossaryChecker::TRANSLATED_DNT, 'term' => 'CloudBed', 'expected' => 'CloudBed'],
        ], (new GlossaryChecker)->check('Von Beratern und Wolkenbett', 'From consultants and cloud bed', 'de', 'en', $terms));
    }

    #[Test]
    public function avoid_wording_in_the_source_activates_the_row_too(): void
    {
        $terms = [GlossaryConcept::of(['de' => ['use' => 'Fachpartner', 'avoid' => ['Verkäufer']], 'en' => ['use' => 'specialist partner']])];

        $this->assertSame(
            [['type' => GlossaryChecker::MISSING_TERM, 'term' => 'Fachpartner', 'expected' => 'specialist partner']],
            (new GlossaryChecker)->check('Der Verkäufer', 'The seller', 'de', 'en', $terms),
        );
    }

    #[Test]
    public function keep_exact_goes_by_the_language_own_use_and_keeps_exact_case(): void
    {
        $terms = [GlossaryConcept::of(['de' => ['use' => 'Acme'], 'fr' => ['use' => 'Akme']], keepExact: true)];
        $checker = new GlossaryChecker;

        $this->assertSame([], $checker->check('Acme', 'Acme is here', 'de', 'en', $terms));
        $this->assertSame([['type' => GlossaryChecker::TRANSLATED_DNT, 'term' => 'Acme', 'expected' => 'Acme']], $checker->check('Acme', 'ACME is here', 'de', 'en', $terms));
        $this->assertSame([], $checker->check('Acme', 'Akme ici', 'de', 'fr', $terms));
        $this->assertSame([['type' => GlossaryChecker::TRANSLATED_DNT, 'term' => 'Acme', 'expected' => 'Akme']], $checker->check('Acme', 'Acme ici', 'de', 'fr', $terms));
    }

    #[Test]
    public function only_missing_required_wording_is_a_hint(): void
    {
        $this->assertFalse(GlossaryChecker::isBlocking(['type' => GlossaryChecker::MISSING_TERM, 'term' => 'a']));
        $this->assertTrue(GlossaryChecker::isBlocking(['type' => GlossaryChecker::TRANSLATED_DNT, 'term' => 'a']));
        $this->assertTrue(GlossaryChecker::isBlocking(['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'a']));
    }

    #[Test]
    public function it_flags_missing_terms_translated_brand_names_and_forbidden_variants(): void
    {
        $terms = [
            GlossaryConcept::of(['en' => ['use' => 'checkout'], 'de' => ['use' => 'Kasse']]),
            GlossaryConcept::of(['en' => ['use' => 'b10cks']], keepExact: true),
            GlossaryConcept::of(['en' => ['use' => 'email'], 'de' => ['use' => 'E-Mail', 'avoid' => ['Email', 'eMail']]]),
        ];

        $findings = (new GlossaryChecker)->check(
            'Go to checkout in b10cks and confirm your email.',
            'Gehe zum Bezahlen in Blöcke und bestätige deine eMail.',
            'en',
            'de',
            $terms,
        );

        $this->assertEqualsCanonicalizing([
            ['type' => GlossaryChecker::MISSING_TERM, 'term' => 'checkout', 'expected' => 'Kasse'],
            ['type' => GlossaryChecker::TRANSLATED_DNT, 'term' => 'b10cks', 'expected' => 'b10cks'],
            ['type' => GlossaryChecker::MISSING_TERM, 'term' => 'email', 'expected' => 'E-Mail'],
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'E-Mail', 'expected' => 'E-Mail', 'found' => 'Email'],
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'E-Mail', 'expected' => 'E-Mail', 'found' => 'eMail'],
        ], $findings);

        $this->assertSame([], (new GlossaryChecker)->check(
            'Go to checkout in b10cks.',
            'Gehe zur Kasse in b10cks.',
            'en',
            'de',
            $terms,
        ));
    }

    #[Test]
    public function a_star_in_banned_wording_finds_inflected_forms_and_reports_what_matched(): void
    {
        $terms = [
            GlossaryConcept::of(['de' => ['avoid' => ['klimaneutral*']]]),
            GlossaryConcept::of(['de' => ['use' => 'Fachhändler', 'avoid' => ['Händler*']], 'en' => ['use' => 'dealer']]),
        ];
        $checker = new GlossaryChecker;

        $this->assertSame([
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'klimaneutral*', 'found' => 'klimaneutrale'],
        ], $checker->check('', 'klimaneutrale Wege', 'de', 'de', $terms));
        $this->assertSame([
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'Fachhändler', 'expected' => 'Fachhändler', 'found' => 'Händlern'],
        ], $checker->check('', 'Wir danken allen Händlern.', 'de', 'de', $terms));
        $this->assertSame([], $checker->check('', 'Der Fachhändler.', 'de', 'de', $terms), 'The star does not reach into a longer word.');
    }

    #[Test]
    public function a_missing_term_is_a_hint_and_the_rest_is_blocking(): void
    {
        $findings = [
            ['type' => GlossaryChecker::MISSING_TERM, 'term' => 'cart', 'expected' => 'Warenkorb'],
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'cart', 'found' => 'Korb'],
        ];

        $this->assertSame([GlossaryChecker::FORBIDDEN_VARIANT], array_column(GlossaryChecker::blocking($findings), 'type'));
        $this->assertSame([GlossaryChecker::MISSING_TERM], array_column(GlossaryChecker::hints($findings), 'type'));
    }
}
