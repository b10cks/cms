<?php

namespace Tests\Unit\Services\Content;

use App\Models\Space\Asset;
use App\Services\Content\AssetHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetHandlerTest extends TestCase
{
    #[Test]
    public function repeated_assets_keep_the_first_duplicate_and_leave_missing_assets_unchanged(): void
    {
        $first = new class extends Asset
        {
            public function getUrl(): ?string
            {
                return 'https://cdn.example.com/'.$this->filename;
            }
        };
        $first->forceFill(['id' => 'asset-01', 'filename' => 'first', 'metadata' => []]);
        $duplicate = clone $first;
        $duplicate->filename = 'second';
        $node = ['type' => 'asset', 'id' => 'asset-01'];
        $missing = ['type' => 'asset', 'id' => 'missing'];
        $payload = ['images' => [$node, $missing, $node]];
        $assets = collect([$first, $duplicate]);
        $handler = new AssetHandler;

        foreach ([$handler->updateContentAssets($payload, $assets), $handler->replaceContentAssets([], $payload, $assets)] as $result) {
            $this->assertSame('first', $result['images'][0]['filename']);
            $this->assertSame($missing, $result['images'][1]);
            $this->assertSame($result['images'][0], $result['images'][2]);
        }
    }

    #[Test]
    public function requested_languages_win_over_default_fields_in_order(): void
    {
        $asset = new class extends Asset
        {
            public function getUrl(): ?string
            {
                return null;
            }
        };
        $asset->forceFill(['id' => 'asset-01', 'metadata' => [], 'data' => ['fields' => [
            '_default' => ['alt' => 'Default', 'title' => 'Title'],
            'de' => ['alt' => 'Deutsch'],
        ]]]);
        $handler = new AssetHandler;
        $node = ['type' => 'asset', 'id' => 'asset-01'];

        $german = $handler->replaceContentAssets(['de'], $node, collect([$asset]));
        $austrian = $handler->replaceContentAssets(['de-at', 'de'], $node, collect([$asset]));
        $default = $handler->replaceContentAssets([], $node, collect([$asset]));

        $this->assertSame('Deutsch', $german['data']['alt']);
        $this->assertSame('Title', $german['data']['title']);
        $this->assertSame('Deutsch', $austrian['data']['alt']);
        $this->assertSame('Default', $default['data']['alt']);
    }

    #[Test]
    public function a_usage_focus_overrides_the_library_focus_which_stays_the_default(): void
    {
        $asset = new class extends Asset
        {
            public function getUrl(): ?string
            {
                return null;
            }
        };
        $asset->forceFill(['id' => 'asset-01', 'metadata' => [], 'data' => ['focus' => ['x' => 10, 'y' => 20]]]);
        $handler = new AssetHandler;

        $overridden = $handler->replaceContentAssets([], ['type' => 'asset', 'id' => 'asset-01', 'data' => ['focus' => ['x' => 70, 'y' => 80]]], collect([$asset]));
        $inherited = $handler->replaceContentAssets([], ['type' => 'asset', 'id' => 'asset-01', 'data' => []], collect([$asset]));

        $this->assertSame(['x' => 70, 'y' => 80], $overridden['data']['focus']);
        $this->assertSame(['x' => 10, 'y' => 20], $inherited['data']['focus']);
    }

    #[Test]
    public function it_extracts_assets_from_a_root_level_asset_payload(): void
    {
        $handler = new AssetHandler;

        $assetIds = $handler->extractContentAssets([
            'type' => 'asset',
            'id' => '01k0s6v4rsjjjgkhepj496dj5e',
        ]);

        $this->assertSame(['01k0s6v4rsjjjgkhepj496dj5e'], $assetIds);
    }

    #[Test]
    public function extraction_keeps_only_ulid_asset_ids(): void
    {
        $assetIds = (new AssetHandler)->extractContentAssets(['images' => [
            ['type' => 'asset', 'id' => '01k0s6v4rsjjjgkhepj496dj5e'],
            ['type' => 'asset', 'id' => 'https://example.com/a-long-legacy-reference.jpg'],
            ['type' => 'asset', 'id' => 42],
            ['type' => 'asset', 'id' => ['nested']],
        ]]);

        $this->assertSame(['01k0s6v4rsjjjgkhepj496dj5e'], $assetIds);
    }

    #[Test]
    public function extraction_also_keeps_the_asset_id_named_by_the_full_path(): void
    {
        $assetIds = (new AssetHandler)->extractContentAssets(['image' => [
            'type' => 'asset',
            'id' => '01m5ba8pv7mv38safa2yt4zc5f',
            'full_path' => 'storage-01/01kkk9mqjw2kn06vknz28t7ncg/01m58s7v6hxmf6b2vmb34hkhwv/032A9365.jpg',
        ]]);

        $this->assertSame(['01m5ba8pv7mv38safa2yt4zc5f', '01m58s7v6hxmf6b2vmb34hkhwv'], $assetIds);
    }

    #[Test]
    public function a_reference_with_a_foreign_id_resolves_through_its_full_path_and_gets_the_real_id(): void
    {
        $asset = new class extends Asset
        {
            public function getUrl(): ?string
            {
                return null;
            }
        };
        $asset->forceFill(['id' => 'asset-01', 'storage_id' => 'storage-01', 'path' => 'space-01/asset-01/renamed.jpg', 'filename' => 'renamed', 'metadata' => []]);
        $stale = ['type' => 'asset', 'id' => 'pasted-01', 'full_path' => 'storage-01/space-01/asset-01/old.jpg', 'filename' => 'old'];
        $handler = new AssetHandler;

        foreach ([$handler->updateContentAssets($stale, collect([$asset])), $handler->replaceContentAssets([], $stale, collect([$asset]))] as $result) {
            $this->assertSame('asset-01', $result['id']);
            $this->assertSame('renamed', $result['filename']);
            $this->assertSame('storage-01/space-01/asset-01/renamed.jpg', $result['full_path']);
        }
    }

    #[Test]
    public function the_reference_id_wins_over_the_full_path_when_both_name_an_asset(): void
    {
        $remapped = new class extends Asset
        {
            public function getUrl(): ?string
            {
                return null;
            }
        };
        $remapped->forceFill(['id' => 'new-01', 'filename' => 'new', 'metadata' => []]);
        $original = (clone $remapped)->forceFill(['id' => 'old-01', 'filename' => 'old']);

        $result = (new AssetHandler)->replaceContentAssets([], [
            'type' => 'asset',
            'id' => 'new-01',
            'full_path' => 'storage-01/space-01/old-01/old.jpg',
        ], collect([$remapped, $original]));

        $this->assertSame('new-01', $result['id']);
        $this->assertSame('new', $result['filename']);
    }

    #[Test]
    public function remapping_rewrites_known_asset_ids_and_keeps_the_rest_of_the_node(): void
    {
        $payload = ['hero' => ['type' => 'asset', 'id' => 'old-1', 'data' => ['alt' => 'Hero']], 'other' => ['type' => 'asset', 'id' => 'unknown']];

        $remapped = (new AssetHandler)->remapContentAssets($payload, ['old-1' => 'new-1']);

        $this->assertSame(['type' => 'asset', 'id' => 'new-1', 'data' => ['alt' => 'Hero']], $remapped['hero']);
        $this->assertSame($payload['other'], $remapped['other']);
    }

    #[Test]
    public function it_updates_root_level_asset_payloads(): void
    {
        $handler = new AssetHandler;
        $asset = new class extends Asset
        {
            public function getUrl(): ?string
            {
                return 'https://cdn.example.com/assets/asset-01.jpg';
            }
        };
        $asset->forceFill([
            'id' => 'asset-01',
            'storage_id' => 'storage-01',
            'path' => 'space/asset-01.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 42,
            'filename' => 'asset-01',
            'metadata' => ['width' => 800, 'height' => 600, 'exif' => ['make' => 'should-not-leak']],
        ]);

        $payload = $handler->updateContentAssets([
            'type' => 'asset',
            'id' => 'asset-01',
            'metadata' => ['width' => 1, 'height' => 1],
        ], collect([$asset]));

        $this->assertSame('https://cdn.example.com/assets/asset-01.jpg', $payload['url']);
        $this->assertSame('asset-01', $payload['filename']);
        $this->assertSame('storage-01/space/asset-01.jpg', $payload['full_path']);
        $this->assertSame(['width' => 800, 'height' => 600], $payload['metadata']);
    }

    #[Test]
    public function it_exposes_dominant_color_and_a11y_stats_in_replaced_content_payloads(): void
    {
        $handler = new AssetHandler;
        $asset = new class extends Asset
        {
            public function getUrl(): ?string
            {
                return 'https://cdn.example.com/assets/asset-01.jpg';
            }
        };
        $asset->forceFill([
            'id' => 'asset-01',
            'storage_id' => 'storage-01',
            'path' => 'space/asset-01.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 42,
            'filename' => 'asset-01',
            'metadata' => [
                'width' => 800,
                'height' => 600,
                'dominant_color' => '#112233',
                'palette' => ['#112233', '#445566'],
                'a11y' => ['scheme' => 'dark', 'luminance' => 0.014, 'contrast_white' => 16.4, 'contrast_black' => 1.28],
                'exif' => ['make' => 'should-not-leak'],
            ],
        ]);

        $payload = $handler->replaceContentAssets([], [
            'type' => 'asset',
            'id' => 'asset-01',
        ], collect([$asset]));

        $this->assertSame('#112233', $payload['metadata']['dominant_color']);
        $this->assertSame('dark', $payload['metadata']['a11y']['scheme']);
        $this->assertSame(800, $payload['metadata']['width']);
        // Only whitelisted metadata keys are delivered with content.
        $this->assertArrayNotHasKey('exif', $payload['metadata']);
        $this->assertArrayNotHasKey('palette', $payload['metadata']);
    }
}
