<?php

namespace Tests\Unit\Services\Content\Serial;

use App\Services\Content\Serial\TemplateRenderer;
use App\Services\Content\Serial\Tokens\CounterToken;
use App\Services\Content\Serial\Tokens\DateToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(TemplateRenderer::class)]
class TemplateRendererTest extends TestCase
{
    protected function renderer(): TemplateRenderer
    {
        return app(TemplateRenderer::class);
    }

    #[Test]
    public function it_registers_every_shipped_token(): void
    {
        $this->assertSame(
            ['counter', 'parent', 'ancestor', 'field', 'block', 'date', 'lang'],
            array_keys($this->renderer()->resolvers()),
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function numberTokenCases(): array
    {
        return [
            'bare counter' => ['{counter}', true],
            'padded counter' => ['{prefix}-{counter:3}', true],
            'no counter' => ['{parent:sku}-{date:Y}', false],
            'literal only' => ['static', false],
        ];
    }

    #[Test]
    #[DataProvider('numberTokenCases')]
    public function it_detects_whether_a_template_draws_a_number(string $template, bool $expected): void
    {
        $this->assertSame($expected, $this->renderer()->requiresNumber($template));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTemplates(): array
    {
        return [
            'empty' => ['   ', 'The pattern may not be empty.'],
            'no tokens' => ['just-text', 'The pattern must contain at least one token.'],
            'unknown token' => ['{nope}-{counter}', '`{nope}` is not a known token.'],
            'malformed brace' => ['{counter}-{unclosed', 'The pattern contains a malformed token.'],
            'bad date format' => [
                '{date:Y-m-d H:i}-{counter}',
                '`{date:Y-m-d H:i}` is not a supported date format.',
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidTemplates')]
    public function it_rejects_invalid_templates(string $template, string $expectedMessage): void
    {
        $this->assertContains($expectedMessage, $this->renderer()->validate($template));
    }

    #[Test]
    public function it_accepts_a_well_formed_template(): void
    {
        $this->assertSame([], $this->renderer()->validate('{ancestor:house_no}-{counter:3}'));
    }

    #[Test]
    public function it_rejects_a_date_format_outside_the_allow_list(): void
    {
        // Escapes and arbitrary characters could smuggle text into an
        // identifier, so only date-shaped format strings are accepted.
        $errors = $this->renderer()->validate('{date:\\Q}-{counter}');

        $this->assertNotSame([], $errors);
    }

    #[Test]
    public function slug_patterns_may_not_draw_a_number(): void
    {
        $this->assertContains(
            '`{counter}` can only be used in a serial format.',
            $this->renderer()->validate('{counter}-{field:name}', allowNumberTokens: false),
        );
    }

    #[Test]
    public function padding_is_bounded(): void
    {
        $token = new CounterToken;
        $context = $this->makeContext(7);

        $this->assertSame('007', $token->resolve('3', $context));
        $this->assertSame('7', $token->resolve('0', $context), 'A zero pad is no pad.');
        $this->assertSame(
            '7',
            $token->resolve((string) (CounterToken::MAX_PADDING + 1), $context),
            'An absurd padding must not produce a megabyte-long identifier.',
        );
    }

    #[Test]
    public function the_date_token_falls_back_to_the_year(): void
    {
        $this->assertSame(
            now()->format('Y'),
            (new DateToken)->resolve('not a format', $this->makeContext(1)),
        );
    }

    protected function makeContext(int $number): \App\Services\Content\Serial\SerialContext
    {
        $block = new \App\Models\Space\Block(['name' => 'House', 'slug' => 'house']);

        return new \App\Services\Content\Serial\SerialContext(
            block: $block,
            parent: null,
            languageIso: 'en',
            values: [],
            number: $number,
        );
    }
}
