<?php

namespace Tests\Unit\Services\Content;

use App\Models\Space\Asset;
use App\Models\Space\Content;
use App\Services\Content\AssetHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetHandlerTest extends TestCase
{
    #[Test]
    public function it_extracts_assets_from_a_root_level_asset_payload(): void
    {
        $handler = new AssetHandler;

        $assetIds = $handler->extractContentAssets([
            'type' => 'asset',
            'id' => 'asset-01',
        ]);

        $this->assertSame(['asset-01'], $assetIds);
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
        ]);

        $payload = $handler->updateContentAssets([
            'type' => 'asset',
            'id' => 'asset-01',
        ], collect([$asset]));

        $this->assertSame('https://cdn.example.com/assets/asset-01.jpg', $payload['url']);
        $this->assertSame('asset-01', $payload['filename']);
        $this->assertSame('storage-01/space/asset-01.jpg', $payload['full_path']);
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

        $content = new Content;
        $content->forceFill(['language_iso' => 'en', 'i18n_parent' => null]);

        $payload = $handler->replaceContentAssets($content, [
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
