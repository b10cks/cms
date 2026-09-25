<?php

namespace Tests\Unit\Services\Content;

use App\Http\Resources\Api\ContentResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Content\ContentFieldSelector;
use App\Services\Content\ResolvedContent;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentProjectionTest extends TestCase
{
    #[Test]
    public function projection_preserves_transformed_nested_fields_and_skips_discarded_assets(): void
    {
        $asset = $this->asset();
        $node = ['type' => 'asset', 'id' => $asset->id];
        $payload = [
            'title' => 'Page',
            'hero' => $node,
            'sections' => [
                ['block' => 'image', 'hidden' => true, 'image' => $node],
                ['block' => 'image', 'image' => $node],
            ],
        ];
        $full = $this->render($payload, $asset);

        foreach ([
            ['take', 'title,block', 0],
            ['take', 'hero.url,sections.0.image.url,block', 2],
            ['except', 'hero,sections', 0],
            ['except', 'hero.url', 2],
            ['take', 'unknown', 0],
            ['take', 'invalid-path', 2],
        ] as [$selector, $paths, $expectedUrlCalls]) {
            $asset->urlCalls = 0;
            $parsed = ContentFieldSelector::parsePaths($paths);
            $expected = $parsed === [] ? $full : ContentFieldSelector::{$selector}($full, $parsed);

            $this->assertSame($expected, $this->render($payload, $asset, [$selector => $paths]));
            $this->assertSame($expectedUrlCalls, $asset->urlCalls, $selector.'='.$paths);
        }
    }

    #[Test]
    public function numeric_root_fields_keep_their_original_projection_indexes(): void
    {
        $asset = $this->asset();
        $payload = ['first', 'second', 'title' => 'Page'];
        $full = $this->render($payload, $asset);

        $this->assertSame(
            ContentFieldSelector::take($full, ['1']),
            $this->render($payload, $asset, ['take' => '1']),
        );
    }

    #[Test]
    public function selecting_generated_fields_from_a_root_asset_keeps_its_source_fields(): void
    {
        $asset = $this->asset();

        $this->assertSame(
            ['url' => 'https://cdn.example.com/image.jpg'],
            $this->render(['type' => 'asset', 'id' => $asset->id], $asset, ['take' => 'url']),
        );
    }

    private function asset(): Asset
    {
        $asset = new class extends Asset
        {
            public int $urlCalls = 0;

            public function getUrl(): ?string
            {
                $this->urlCalls++;

                return 'https://cdn.example.com/image.jpg';
            }
        };
        $asset->forceFill(['id' => 'asset-01', 'metadata' => [], 'data' => []]);

        return $asset;
    }

    private function render(array $payload, Asset $asset, array $query = []): array
    {
        app()->instance('currentSpace', new Space([
            'settings' => ['default_language' => 'en', 'filter_hidden_blocks' => true],
        ]));
        $row = new Content(['language_iso' => 'en']);
        $row->setRelation('block', new Block(['slug' => 'page']));
        $row->setRelation('i18n_parent', null);
        $resolved = new ResolvedContent(
            canonicalContent: $row,
            familyContents: collect([$row]),
            requestedLanguage: 'en',
            resolvedLanguage: 'en',
            effectiveMode: 'independent',
            resolvedRow: $row,
            targetContent: $row,
            targetVersion: null,
            fallbackContent: null,
            fallbackVersion: null,
            effectiveContent: $payload,
            effectiveBaseContent: [],
            effectiveAssets: collect([$asset]),
            effectiveLinks: collect(),
            effectiveRelations: collect(),
        );
        $resource = new class($resolved) extends ContentResource
        {
            public function transformed(Request $request): array
            {
                return $this->getTransformedContent($this->resource, $request);
            }
        };

        return $resource->transformed(Request::create('/', 'GET', $query));
    }
}
