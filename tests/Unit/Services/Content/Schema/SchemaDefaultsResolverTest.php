<?php

namespace Tests\Unit\Services\Content\Schema;

use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\SchemaDefaultsResolver;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchemaDefaultsResolverTest extends TestCase
{
    private SchemaDefaultsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SchemaDefaultsResolver;
    }

    #[Test]
    public function it_seeds_meaningful_defaults_only(): void
    {
        $schema = BlockSchema::fromArray([
            'title' => ['type' => 'text', 'default' => 'Untitled page'],
            'subtitle' => ['type' => 'text', 'default' => ''],
            'count' => ['type' => 'number', 'default' => 5],
            'zero_count' => ['type' => 'number', 'default' => 0],
            'featured' => ['type' => 'boolean', 'default' => true],
            'hidden' => ['type' => 'boolean', 'default' => false],
            'categories' => ['type' => 'options', 'default' => ['news']],
            'empty_categories' => ['type' => 'options', 'default' => []],
            'body' => ['type' => 'markdown'],
        ]);

        $this->assertSame([
            'title' => 'Untitled page',
            'count' => 5,
            'featured' => true,
            'categories' => ['news'],
        ], $this->resolver->resolve($schema));
    }

    #[Test]
    public function it_seeds_the_current_date_when_configured(): void
    {
        Carbon::setTestNow('2026-07-11 13:37:00');

        $schema = BlockSchema::fromArray([
            'published_on' => ['type' => 'date', 'use_current_as_default' => true],
            'published_at' => ['type' => 'date', 'format' => 'datetime-local', 'use_current_as_default' => true],
            'time' => ['type' => 'date', 'format' => 'time', 'use_current_as_default' => true],
            'other' => ['type' => 'date'],
        ]);

        $this->assertSame([
            'published_on' => '2026-07-11',
            'published_at' => '2026-07-11T13:37',
            'time' => '13:37',
        ], $this->resolver->resolve($schema));

        Carbon::setTestNow();
    }

    #[Test]
    public function it_skips_table_defaults_without_rows(): void
    {
        $schema = BlockSchema::fromArray([
            'empty_table' => ['type' => 'table', 'default' => ['header' => ['col' => 'Col'], 'rows' => []]],
            'seeded_table' => ['type' => 'table', 'default' => ['header' => ['col' => 'Col'], 'rows' => [['col' => 'Value']]]],
        ]);

        $defaults = $this->resolver->resolve($schema);

        $this->assertArrayNotHasKey('empty_table', $defaults);
        $this->assertArrayHasKey('seeded_table', $defaults);
    }
}
