<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GlossaryMatcherTest extends TestCase
{
    #[Test]
    public function a_wording_inside_a_longer_found_wording_is_no_match_and_gives_no_finding(): void
    {
        $terms = [
            GlossaryConcept::of(['de' => ['use' => 'Großes Wolkenbett'], 'en' => ['use' => 'large cloud bed']]),
            GlossaryConcept::of(['de' => ['use' => 'Wolkenbett'], 'en' => ['use' => 'cloud bed']]),
        ];
        $matcher = GlossaryMatcher::for($terms);

        $this->assertSame(['Großes Wolkenbett'], array_map(fn (array $hit) => $hit['wording']->name(), $matcher->find('Das Großes Wolkenbett ist neu.', 'de')));
        $this->assertSame(['Wolkenbett'], array_map(fn (array $hit) => $hit['wording']->name(), $matcher->find('Das Wolkenbett ist neu.', 'de')), 'On its own the shorter wording still matches.');
        $this->assertSame(
            [['type' => GlossaryChecker::MISSING_TERM, 'term' => 'Großes Wolkenbett', 'expected' => 'large cloud bed']],
            (new GlossaryChecker)->check('Das Großes Wolkenbett ist neu.', 'The new place.', 'de', 'en', $terms),
        );
    }

    #[Test]
    public function also_written_as_inside_a_found_wording_is_dropped_and_counts_where_it_stands_alone(): void
    {
        $terms = [
            GlossaryConcept::of(['de' => ['use' => 'Lattenrost Plus', 'also' => ['Rost']]]),
            GlossaryConcept::of(['de' => ['use' => 'Acme', 'also' => ['acme']]], keepExact: true),
        ];

        $hits = GlossaryMatcher::for($terms)->find('Der Lattenrost Plus von Acme und ein Rost.', 'de');

        $this->assertSame(['Lattenrost Plus', 'Rost'], array_column($hits[0]['spans'], 'text'));
        $this->assertSame(['Acme'], array_column($hits[1]['spans'], 'text'));

        $inside = GlossaryMatcher::for([$terms[0]])->find('Der Lattenrost Plus.', 'de');
        $this->assertSame(['Lattenrost Plus'], array_column($inside[0]['spans'], 'text'), 'Rost inside the wording is no match.');
    }

    #[Test]
    public function avoid_wording_activates_its_row_and_a_ban_is_found_by_its_star_entry(): void
    {
        $terms = [
            GlossaryConcept::of(['de' => ['use' => 'Fachpartner', 'avoid' => ['Verkäufer']]]),
            GlossaryConcept::of(['de' => ['avoid' => ['Billig*']]]),
        ];

        $hits = GlossaryMatcher::for($terms)->find('Der Verkäufer der Billigbetten.', 'de');

        $this->assertSame(['Fachpartner', 'Billig*'], array_map(fn (array $hit) => $hit['wording']->name(), $hits));
        $this->assertSame([['start' => 4, 'end' => 14, 'text' => 'Verkäufer', 'avoid' => true]], $hits[0]['spans']);
        $this->assertSame('Billigbetten', $hits[1]['spans'][0]['text']);
    }

    #[Test]
    public function a_star_at_the_end_of_a_banned_word_matches_longer_forms_from_the_start_of_a_word(): void
    {
        $this->assertSame('klimaneutrale', GlossaryMatcher::findBanned('Ein klimaneutrale Lösung', 'klimaneutral*', false));
        $this->assertSame('Händlern', GlossaryMatcher::findBanned('Wir danken Händlern.', 'Händler*', false));
        $this->assertNull(GlossaryMatcher::findBanned('Ein unklimaneutral Wort', 'klimaneutral*', false), 'The start of the word must still match.');
        $this->assertNull(GlossaryMatcher::findBanned('Händlern', 'Händler', false), 'Without the star the entry is exact.');
        $this->assertNull(GlossaryMatcher::findBanned('händler', 'Händler*', true), 'Case follows the term.');
        $this->assertSame('bester Preise', GlossaryMatcher::findBanned('Nur bester Preise hier', 'best* Preis*', false), 'Each word of an entry can end in a star.');
        $this->assertNull(GlossaryMatcher::findBanned('Nur bester Preise hier', 'best* Preis', false), 'A word without a star is exact.');
        $this->assertNull(GlossaryMatcher::findBanned('a*b', '*', false), 'A star with no word before it is plain text.');
    }
}
