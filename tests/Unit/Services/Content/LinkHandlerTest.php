<?php

namespace Tests\Unit\Services\Content;

use App\Models\Space\Content;
use App\Services\Content\LinkHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkHandlerTest extends TestCase
{
    #[Test]
    public function it_extracts_from_a_root_level_internal_link_payload(): void
    {
        $extractor = new LinkHandler;

        $links = $extractor->extractContentLinks([
            'type' => 'internal',
            'content' => 'link-01',
        ]);

        $this->assertSame(['link-01'], $links);
    }

    #[Test]
    public function it_extracts_from_a_simple_structure(): void
    {
        $extractor = new LinkHandler;

        $data = [
            'body' => [
                [
                    'id' => '01jzjra1q24zp1shx80bcrf78j',
                    'link' => [
                        'type' => 'internal',
                        'target' => null,
                        'content' => 'link-01',
                    ],
                    'Label' => 'Test',
                    'block' => 'button',
                ],
            ],
        ];

        $links = $extractor->extractContentLinks($data);

        $this->assertEquals(['link-01'], $links);
    }

    #[Test]
    public function it_handles_deeply_nested_duplicates(): void
    {
        $extractor = new LinkHandler;

        $data = [
            'menu' => [
                [
                    'id' => '01jr8ryvjsk9ehw6d44x66584y',
                    'block' => 'menu',
                    'items' => [
                        [
                            'id' => '01jzjrdscbevbrw07qsf4sx6f4',
                            'block' => 'menu',
                            'items' => [
                                [
                                    'id' => '01jzjrdxgcmcdn03rgrq0j7dyz',
                                    'link' => [
                                        'type' => 'internal',
                                        'target' => null,
                                        'content' => 'link-01',
                                    ],
                                    'block' => 'menuItem',
                                ],
                            ],
                        ],
                    ],
                    'header' => 'menu',
                ],
            ],
            'actions' => [
                [
                    'id' => '01jr8rydqxka6g999w0ms8n8d3',
                    'link' => [
                        'type' => 'internal',
                        'target' => null,
                        'content' => 'link-01',
                    ],
                    'Label' => 'button',
                    'block' => 'button',
                ],
            ],
        ];

        $links = $extractor->extractContentLinks($data);

        $this->assertEquals(['link-01'], $links);
    }

    #[Test]
    public function it_ignores_non_internal_links(): void
    {
        $extractor = new LinkHandler;

        $data = [
            'body' => [
                [
                    'id' => '01',
                    'link' => [
                        'type' => 'external',
                        'target' => 'https://example.com',
                        'content' => 'external-link',
                    ],
                    'block' => 'button',
                ],
                [
                    'id' => '02',
                    'link' => [
                        'type' => 'internal',
                        'target' => null,
                        'content' => 'internal-link',
                    ],
                    'block' => 'button',
                ],
            ],
        ];

        $links = $extractor->extractContentLinks($data);

        $this->assertEquals(['internal-link'], $links);
    }

    #[Test]
    public function it_handles_missing_link_fields_gracefully(): void
    {
        $extractor = new LinkHandler;

        $data = [
            'body' => [
                [
                    'id' => '01',
                    'block' => 'button',
                ],
                [
                    'id' => '02',
                    'link' => [
                        'type' => 'internal',
                        // Missing 'content'
                    ],
                    'block' => 'button',
                ],
            ],
        ];

        $links = $extractor->extractContentLinks($data);

        $this->assertEquals([], $links);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_input(): void
    {
        $extractor = new LinkHandler;

        $data = [];

        $links = $extractor->extractContentLinks($data);

        $this->assertEquals([], $links);
    }

    #[Test]
    public function it_handles_non_array_input(): void
    {
        $extractor = new LinkHandler;

        $data = null;

        $links = $extractor->extractContentLinks((array) $data);

        $this->assertEquals([], $links);
    }

    #[Test]
    public function it_extracts_multiple_unique_internal_links(): void
    {
        $extractor = new LinkHandler;

        $data = [
            'sections' => [
                [
                    'link' => [
                        'type' => 'internal',
                        'content' => 'link-01',
                    ],
                ],
                [
                    'link' => [
                        'type' => 'internal',
                        'content' => 'link-02',
                    ],
                ],
                [
                    'link' => [
                        'type' => 'internal',
                        'content' => 'link-01',
                    ],
                ],
            ],
        ];

        $links = $extractor->extractContentLinks($data);

        $this->assertEquals(['link-01', 'link-02'], $links);
    }

    #[Test]
    public function it_replaces_root_level_internal_link_payloads(): void
    {
        $extractor = new LinkHandler;

        $payload = $extractor->replaceContentLinks([
            'type' => 'internal',
            'content' => 'link-01',
        ], collect([
            tap(new Content, function ($content) {
                $content->forceFill([
                    'id' => 'link-01',
                    'name' => 'Linked Page',
                    'full_slug' => '/linked-page',
                ]);
            }),
        ]));

        $this->assertSame('/linked-page', $payload['url']);
        $this->assertSame('Linked Page', $payload['title']);
        $this->assertSame('link-01', $payload['content']);
    }
}
