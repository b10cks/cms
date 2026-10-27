<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryTerm;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GlossaryCheckerTest extends TestCase
{
    #[Test]
    public function it_flags_missing_terms_translated_brand_names_and_forbidden_variants(): void
    {
        $terms = [
            new GlossaryTerm('checkout', 'en', ['de' => 'Kasse']),
            new GlossaryTerm('b10cks', 'en', doNotTranslate: true, caseSensitive: true),
            new GlossaryTerm('email', 'en', ['de' => 'E-Mail'], forbidden: ['Email', 'eMail']),
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
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'email', 'expected' => 'E-Mail', 'found' => 'Email'],
            ['type' => GlossaryChecker::FORBIDDEN_VARIANT, 'term' => 'email', 'expected' => 'E-Mail', 'found' => 'eMail'],
        ], $findings);

        $this->assertSame([], (new GlossaryChecker)->check(
            'Go to checkout in b10cks.',
            'Gehe zur Kasse in b10cks.',
            'en',
            'de',
            $terms,
        ));
    }
}
