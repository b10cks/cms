<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Support\JsonExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JsonExtractorTest extends TestCase
{
    #[Test]
    public function it_decodes_clean_json(): void
    {
        $this->assertSame(['title' => 'Hi'], JsonExtractor::decode('{"title":"Hi"}'));
    }

    #[Test]
    public function it_strips_markdown_code_fences(): void
    {
        $raw = "```json\n{\"title\":\"Hi\"}\n```";

        $this->assertSame(['title' => 'Hi'], JsonExtractor::decode($raw));
    }

    #[Test]
    public function it_extracts_json_embedded_in_prose(): void
    {
        $raw = 'Sure! Here are your meta tags: {"title":"Hi","description":"There"} Hope this helps.';

        $this->assertSame(
            ['title' => 'Hi', 'description' => 'There'],
            JsonExtractor::decode($raw),
        );
    }

    #[Test]
    public function it_ignores_braces_inside_strings(): void
    {
        $raw = '{"title":"a } b { c"}';

        $this->assertSame(['title' => 'a } b { c'], JsonExtractor::decode($raw));
    }

    #[Test]
    public function it_returns_null_for_unparseable_or_empty_input(): void
    {
        $this->assertNull(JsonExtractor::decode(null));
        $this->assertNull(JsonExtractor::decode(''));
        $this->assertNull(JsonExtractor::decode('not json at all'));
    }
}
