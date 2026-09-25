<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AssetClassificationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetClassificationServiceTest extends TestCase
{
    private AssetClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AssetClassificationService::class);
    }

    #[Test]
    public function it_parses_flat_keys_and_keeps_only_requested_targets(): void
    {
        $parsed = $this->service->parseResponse(
            "```json\n{\"_default.title\":\"Hello\",\"de.alt\":\" Beschreibung \",\"_default.alt\":\"\",\"de.title\":\"Nicht angefragt\",\"junk\":1}\n```",
            ['_default.title', '_default.alt', 'de.alt'],
        );

        $this->assertSame([
            'fields' => [
                '_default' => ['title' => 'Hello'],
                'de' => ['alt' => 'Beschreibung'],
            ],
            'tags' => [],
        ], $parsed);
    }

    #[Test]
    public function it_returns_nothing_for_unparseable_output(): void
    {
        $this->assertSame(
            ['fields' => [], 'tags' => []],
            $this->service->parseResponse('Sorry, I cannot see the image.', ['_default.title']),
        );
    }

    #[Test]
    public function it_truncates_runaway_values(): void
    {
        $parsed = $this->service->parseResponse(
            json_encode(['_default.description' => str_repeat('a', 1500)]),
            ['_default.description'],
        );

        $this->assertSame(1000, mb_strlen($parsed['fields']['_default']['description']));
    }

    #[Test]
    public function it_maps_suggested_tags_to_taxonomy_ids_case_insensitively(): void
    {
        $parsed = $this->service->parseResponse(
            json_encode(['tags' => ['Outdoor', 'unknown tag', 'PEOPLE', 'outdoor']]),
            [],
            ['outdoor' => 'tag-1', 'people' => 'tag-2'],
        );

        $this->assertSame(['tag-1', 'tag-2'], $parsed['tags']);
    }

    #[Test]
    public function it_only_fills_empty_values_when_merging(): void
    {
        $merged = $this->service->mergeFields(
            [
                'focus' => ['x' => 0.5, 'y' => 0.5],
                'fields' => [
                    '_default' => ['title' => 'Existing title', 'alt' => '   '],
                    'de' => ['title' => ''],
                ],
            ],
            [
                '_default' => ['title' => 'Generated title', 'alt' => 'Generated alt'],
                'de' => ['title' => 'Generierter Titel'],
            ],
        );

        $this->assertSame(['x' => 0.5, 'y' => 0.5], $merged['focus']);
        $this->assertSame('Existing title', $merged['fields']['_default']['title']);
        $this->assertSame('Generated alt', $merged['fields']['_default']['alt']);
        $this->assertSame('Generierter Titel', $merged['fields']['de']['title']);
    }

    #[Test]
    public function it_replaces_values_when_overwriting(): void
    {
        $merged = $this->service->mergeFields(
            ['fields' => ['_default' => ['title' => 'Existing title']]],
            ['_default' => ['title' => 'Generated title']],
            overwrite: true,
        );

        $this->assertSame('Generated title', $merged['fields']['_default']['title']);
    }
}
