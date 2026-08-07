<?php

namespace Tests\Unit\Services\Slug;

use App\Services\Slug\Slugger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Slugger::class)]
class SluggerTest extends TestCase
{
    private Slugger $slugger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slugger = new Slugger();
    }

    /**
     * The shared case table, also consumed by tests/js/lib/slug.test.ts.
     *
     * @return array<string, array{string, string|null, string}>
     */
    public static function sharedCases(): array
    {
        return self::fixtureGroup('cases', static fn (array $case): array => [
            $case['value'],
            $case['language'],
            $case['expected'],
        ]);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function truncationCases(): array
    {
        return self::fixtureGroup('truncation', static fn (array $case): array => [
            $case['value'],
            $case['maxLength'],
            $case['expected'],
        ]);
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function identifierCases(): array
    {
        return self::fixtureGroup('identifiers', static fn (array $case): array => [
            $case['value'],
            $case['language'],
            $case['expected'],
        ]);
    }

    #[Test]
    #[DataProvider('sharedCases')]
    public function it_matches_the_shared_case_table(string $value, ?string $language, string $expected): void
    {
        $this->assertSame($expected, $this->slugger->forContent($value, $language));
    }

    #[Test]
    #[DataProvider('truncationCases')]
    public function it_truncates_on_a_word_boundary(string $value, int $maxLength, string $expected): void
    {
        $this->assertSame($expected, $this->slugger->make($value, 'en', '-', $maxLength));
    }

    #[Test]
    #[DataProvider('identifierCases')]
    public function it_strips_underscores_from_identifier_slugs(string $value, ?string $language, string $expected): void
    {
        $this->assertSame($expected, $this->slugger->forIdentifier($value, $language));
    }

    /**
     * The frontend has no transliteration table and yields "" for these, which is
     * why they are not in the shared fixture — see the note in ~/lib/slug.
     */
    #[Test]
    public function it_transliterates_non_latin_scripts(): void
    {
        $this->assertSame('privet-mir', $this->slugger->forContent('Привет мир', 'ru'));
        $this->assertSame('dobar-dan', $this->slugger->forContent('Добар дан', 'sr'));
    }

    #[Test]
    public function it_keeps_content_slugs_within_the_column_limit(): void
    {
        $slug = $this->slugger->forContent(str_repeat('wort ', 40), 'de');

        $this->assertLessThanOrEqual(Slugger::CONTENT_SLUG_LENGTH, mb_strlen($slug));
        // The cut lands between words, so the slug never ends mid-word.
        $this->assertStringEndsWith('wort', $slug);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        foreach (['Über Größe', 'Bed & Breakfast', 'my_page', '{lang}/about'] as $value) {
            $once = $this->slugger->forContent($value, 'de');

            $this->assertSame($once, $this->slugger->forContent($once, 'de'), $value);
        }
    }

    #[Test]
    public function it_honours_a_custom_separator(): void
    {
        $this->assertSame('feld_groesse', $this->slugger->make('Feld Größe', 'de', '_'));
    }

    #[Test]
    public function it_escapes_like_wildcards(): void
    {
        // Slugs written before underscores were normalized can still hold one,
        // and an unescaped `_` matches any character in a LIKE prefix query.
        $this->assertSame('my\_page', $this->slugger->escapeLike('my_page'));
        $this->assertSame('a\%b', $this->slugger->escapeLike('a%b'));
    }

    #[Test]
    public function it_falls_back_when_nothing_survives(): void
    {
        $this->assertSame('untitled', $this->slugger->makeWithFallback('🚀', 'untitled'));
        $this->assertSame('rocket', $this->slugger->makeWithFallback('Rocket', 'untitled'));
    }

    /**
     * @param  callable(array<string, mixed>): array<int, mixed>  $map
     * @return array<string, array<int, mixed>>
     */
    private static function fixtureGroup(string $group, callable $map): array
    {
        // Not base_path(): data providers run before the application boots.
        $fixture = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 3).'/fixtures/slug-cases.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $cases = [];

        foreach ($fixture[$group] as $case) {
            $cases[$case['why']] = $map($case);
        }

        return $cases;
    }
}
