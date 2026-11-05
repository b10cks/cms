<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Knowledge\GlossaryBrief;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\PolicySnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GlossaryBriefTest extends TestCase
{
    /**
     * @param  list<GlossaryConcept>  $concepts
     */
    private function brief(array $concepts, string $text, string $source = 'de', string $target = 'en'): string
    {
        return (string) GlossaryBrief::render(PolicySnapshot::fromConcepts($concepts)->activate($text, $source, $target), $source, $target);
    }

    #[Test]
    public function a_row_reads_as_one_plain_line_with_use_avoid_and_its_note(): void
    {
        $section = $this->brief([
            GlossaryConcept::of(['de' => ['use' => 'Bett'], 'en' => ['use' => 'bed']]),
            GlossaryConcept::of(['de' => ['use' => 'Sessel'], 'en' => ['use' => 'armchair']], note: 'Only for the living room line.'),
        ], 'Das Bett und der Sessel.');

        $this->assertStringContainsString('- "Bett": write "bed" in en', $section);
        $this->assertStringContainsString('- "Sessel": write "armchair" in en; note: Only for the living room line.', $section);
        $this->assertStringStartsWith('Terms found in the source (de). Follow each line.', $section);
        $this->assertNull(GlossaryBrief::render([], 'de', 'en'));
    }

    #[Test]
    public function avoid_wording_comes_from_the_output_language(): void
    {
        $section = $this->brief([
            GlossaryConcept::of(['de' => ['use' => 'Stuhl', 'avoid' => ['Hocker']], 'en' => ['use' => 'chair', 'avoid' => ['seat', 'stool*']]], id: 'c1'),
        ], 'Ein Stuhl.');

        $this->assertStringContainsString('- "Stuhl": write "chair" in en; never use "seat", "stool*"', $section);
    }

    #[Test]
    public function a_source_that_wrote_avoid_wording_is_told_never_to_use_it_and_what_to_write(): void
    {
        $use = GlossaryConcept::of(['de' => ['use' => 'Fachpartner', 'avoid' => ['Verkäufer']], 'en' => ['use' => 'specialist partner']]);
        $ban = GlossaryConcept::of(['de' => ['avoid' => ['Billigbett', 'Billig*']]]);

        $this->assertStringContainsString('- "Verkäufer": never use; write "Fachpartner"', $this->brief([$use], 'Der Verkäufer.', 'de', 'de'));
        $this->assertStringContainsString('- "Verkäufer": never use; write "specialist partner" in en', $this->brief([$use], 'Der Verkäufer.'));
        $this->assertStringContainsString('- "Billigbetten": never use', $this->brief([$ban], 'Unsere Billigbetten.', 'de', 'de'), 'A ban has nothing to write, and its star entry covers the word.');
    }

    #[Test]
    public function a_keep_exact_name_stays_or_takes_the_language_own_use(): void
    {
        $terms = [
            GlossaryConcept::of(['de' => ['use' => 'Acme']], keepExact: true),
            GlossaryConcept::of(['de' => ['use' => 'Comet'], 'en' => ['use' => 'Bolt']], keepExact: true),
        ];

        $section = $this->brief($terms, 'Acme und Comet.');

        $this->assertStringContainsString('- "Acme": keep exactly as "Acme"', $section);
        $this->assertStringContainsString('- "Comet": write "Bolt" in en', $section);
    }

    #[Test]
    public function also_written_as_gets_the_row_normal_instruction(): void
    {
        $concepts = [GlossaryConcept::of(['de' => ['use' => 'Lattenrost', 'also' => ['Lattenroste']], 'en' => ['use' => 'slatted frame']])];
        $section = $this->brief($concepts, 'Zwei Lattenroste.');

        $this->assertStringContainsString('- "Lattenrost": write "slatted frame" in en', $section);
        $this->assertStringContainsString('- "Lattenrost": use this wording', $this->brief($concepts, 'Zwei Lattenroste.', 'de', 'de'));
    }

    #[Test]
    public function a_note_says_it_is_judgment_and_nothing_checks_it(): void
    {
        $section = $this->brief([GlossaryConcept::of(['de' => ['use' => 'Sessel']], note: 'Only for the living room line.')], 'Der Sessel.');

        $this->assertStringContainsString('nothing checks it', $section);
        $this->assertStringNotContainsString('nothing checks it', $this->brief([GlossaryConcept::of(['de' => ['use' => 'Bett']])], 'Das Bett.'));
    }

    #[Test]
    public function bans_list_target_wording_the_source_never_mentioned_within_a_limit(): void
    {
        $concepts = [
            GlossaryConcept::of(['de' => ['use' => 'Bett'], 'en' => ['use' => 'bed', 'avoid' => ['cot']]], id: 'found'),
            GlossaryConcept::of(['de' => ['use' => 'Stuhl'], 'en' => ['use' => 'chair', 'avoid' => ['stool']]], id: 'other'),
            GlossaryConcept::of(['en' => ['avoid' => ['cheap*']]], id: 'ban'),
            GlossaryConcept::of(['de' => ['avoid' => ['Billig']]], id: 'german'),
        ];
        $hits = PolicySnapshot::fromConcepts($concepts)->activate('Das Bett', 'de', 'en');

        $this->assertSame(
            'Also never write in en: "cheap*", "stool" (write "chair"). A * covers longer forms.',
            GlossaryBrief::bans($concepts, $hits, 'en'),
            'A row the text activated is told already, and a ban comes first.',
        );
        $this->assertNull(GlossaryBrief::bans([$concepts[3]], [], 'en'));
        $this->assertSame('Also never write in en: "stool" (write "chair").', GlossaryBrief::bans([$concepts[1]], [], 'en'), 'No star listed, so no word about stars.');

        $many = array_map(static fn (int $index) => GlossaryConcept::of(['en' => ['avoid' => ["banned wording {$index}"]]], id: "c{$index}"), range(1, 200));
        $bans = (string) GlossaryBrief::bans($many, [], 'en');

        $this->assertLessThan(800, mb_strlen($bans));
        $this->assertStringContainsString('cut short', $bans);
    }
}
