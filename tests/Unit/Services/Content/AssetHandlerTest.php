<?php

namespace Tests\Unit\Services\Content;

use App\Models\Space\Asset;
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
}
