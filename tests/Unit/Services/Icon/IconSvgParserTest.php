<?php

namespace Tests\Unit\Services\Icon;

use App\Services\Icon\IconSvgParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IconSvgParserTest extends TestCase
{
    private IconSvgParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IconSvgParser();
    }

    // -------------------------------------------------------------------------
    // Dimensions
    // -------------------------------------------------------------------------

    #[Test]
    public function it_extracts_dimensions_from_viewBox(): void
    {
        $result = $this->parser->parse('<svg viewBox="0 0 32 32"><path d="M0 0"/></svg>');

        $this->assertSame(32, $result['width']);
        $this->assertSame(32, $result['height']);
    }

    #[Test]
    public function it_falls_back_to_width_height_attributes_when_no_viewBox(): void
    {
        $result = $this->parser->parse('<svg width="48" height="48"><path d="M0 0"/></svg>');

        $this->assertSame(48, $result['width']);
        $this->assertSame(48, $result['height']);
    }

    #[Test]
    public function it_defaults_to_24x24_when_no_dimensions(): void
    {
        $result = $this->parser->parse('<svg><path d="M0 0"/></svg>');

        $this->assertSame(24, $result['width']);
        $this->assertSame(24, $result['height']);
    }

    // -------------------------------------------------------------------------
    // Body extraction
    // -------------------------------------------------------------------------

    #[Test]
    public function it_strips_the_svg_wrapper_and_keeps_children(): void
    {
        $result = $this->parser->parse('<svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg>');

        $this->assertStringNotContainsString('<svg', $result['body']);
        $this->assertStringContainsString('<path', $result['body']);
    }

    // -------------------------------------------------------------------------
    // Presentation attribute hoisting — the core behaviour under test
    // -------------------------------------------------------------------------

    #[Test]
    public function it_hoists_fill_none_into_a_g_wrapper(): void
    {
        $svg = '<svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14"/></svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringContainsString('<g fill="none">', $result['body']);
        $this->assertStringContainsString('<path', $result['body']);
    }

    #[Test]
    public function it_hoists_stroke_attributes_into_a_g_wrapper(): void
    {
        $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M5 12h14"/>'
            . '</svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringContainsString('fill="none"', $result['body']);
        $this->assertStringContainsString('stroke="currentColor"', $result['body']);
        $this->assertStringContainsString('stroke-width="2"', $result['body']);
        $this->assertStringContainsString('stroke-linecap="round"', $result['body']);
        $this->assertStringContainsString('stroke-linejoin="round"', $result['body']);
        // All attrs must be on a single <g>, not scattered
        $this->assertMatchesRegularExpression('/<g\s[^>]*fill="none"[^>]*>/', $result['body']);
    }

    #[Test]
    public function it_does_not_wrap_in_g_when_no_presentation_attributes(): void
    {
        $svg = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringNotContainsString('<g', $result['body']);
    }

    #[Test]
    public function it_hoists_opacity_and_color(): void
    {
        $svg = '<svg viewBox="0 0 24 24" color="red" opacity="0.8"><path d="M0 0"/></svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringContainsString('color="red"', $result['body']);
        $this->assertStringContainsString('opacity="0.8"', $result['body']);
    }

    #[Test]
    public function it_does_not_include_structural_attributes_in_g(): void
    {
        $svg = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"><path d="M0 0"/></svg>';

        $result = $this->parser->parse($svg);

        // Only fill should be in the <g> — not xmlns, viewBox, width, height
        $body = $result['body'];
        $this->assertStringNotContainsString('xmlns', $body);
        $this->assertStringNotContainsString('viewBox', $body);
        $this->assertStringNotContainsString('width=', $body);
        $this->assertStringNotContainsString('height=', $body);
        $this->assertStringContainsString('fill="none"', $body);
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    #[Test]
    public function it_removes_script_elements(): void
    {
        $svg = '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0"/></svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringNotContainsString('<script', $result['body']);
        $this->assertStringContainsString('<path', $result['body']);
    }

    #[Test]
    public function it_removes_event_handler_attributes(): void
    {
        $svg = '<svg viewBox="0 0 24 24"><path d="M0 0" onload="alert(1)"/></svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringNotContainsString('onload', $result['body']);
    }

    #[Test]
    public function it_removes_external_href_references(): void
    {
        $svg = '<svg viewBox="0 0 24 24"><use href="https://evil.com/sprite.svg#icon"/></svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringNotContainsString('https://', $result['body']);
    }

    #[Test]
    public function it_keeps_internal_fragment_href_references(): void
    {
        $svg = '<svg viewBox="0 0 24 24"><defs><path id="p" d="M0 0"/></defs><use href="#p"/></svg>';

        $result = $this->parser->parse($svg);

        $this->assertStringContainsString('href="#p"', $result['body']);
    }

    // -------------------------------------------------------------------------
    // Error cases
    // -------------------------------------------------------------------------

    #[Test]
    public function it_throws_on_empty_input(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Empty SVG document.');

        $this->parser->parse('');
    }

    #[Test]
    public function it_throws_when_root_is_not_svg(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Root element is not <svg>.');

        $this->parser->parse('<div><path d="M0 0"/></div>');
    }

    #[Test]
    public function it_throws_on_svg_with_no_renderable_content(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SVG has no renderable content.');

        $this->parser->parse('<svg viewBox="0 0 24 24"></svg>');
    }

    #[Test]
    public function it_throws_on_doctype(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SVG must not contain a DOCTYPE declaration.');

        $this->parser->parse('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" ""><svg><path d="M0 0"/></svg>');
    }
}
