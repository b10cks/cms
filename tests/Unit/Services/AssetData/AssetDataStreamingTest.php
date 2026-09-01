<?php

namespace Tests\Unit\Services\AssetData;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Asset\AssetMetadataFieldResolver;
use App\Services\AssetData\DataMapper;
use App\Services\AssetData\Drivers\CsvAssetDataDriver;
use App\Services\AssetData\Drivers\JsonAssetDataDriver;
use App\Services\AssetData\Drivers\YamlAssetDataDriver;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

class AssetDataStreamingTest extends TestCase
{
    #[Test]
    public function csv_import_rows_are_read_lazily(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-data-');
        file_put_contents($path, "id,filename\nasset-1,photo.jpg\nasset-2,video.mp4\n");

        try {
            $driver = new CsvAssetDataDriver(
                $this->createMock(DataMapper::class),
                $this->createMock(AssetMetadataFieldResolver::class),
            );

            $rows = $driver->parseFile(new UploadedFile($path, 'assets.csv', test: true));

            $this->assertInstanceOf(\Generator::class, $rows);
            $this->assertSame([
                ['id' => 'asset-1', 'filename' => 'photo.jpg'],
                ['id' => 'asset-2', 'filename' => 'video.mp4'],
            ], iterator_to_array($rows));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    #[DataProvider('streamedFormats')]
    public function structured_exports_are_streamed_as_valid_documents(string $driverClass, callable $decode): void
    {
        $space = new Space;
        $space->setRawAttributes(['id' => 'space-1']);

        $asset = new Asset;
        $asset->setRawAttributes(['id' => 'asset-1']);

        $mapper = $this->createMock(DataMapper::class);
        $mapper->expects($this->once())
            ->method('flattenAsset')
            ->willReturn(['id' => 'asset-1', 'filename' => 'photo.jpg']);

        $resolver = $this->createMock(AssetMetadataFieldResolver::class);
        $resolver->expects($this->once())
            ->method('getEffectiveFieldsForAsset')
            ->with($space, $asset)
            ->willReturn([]);

        $driver = new $driverClass($mapper, $resolver);
        $response = $driver->export($space, [$asset], [], []);

        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $document = $decode($content);

        $this->assertSame('space-1', $document['space_id']);
        $this->assertSame('asset-1', $document['assets'][0]['id']);
    }

    public static function streamedFormats(): iterable
    {
        yield 'JSON' => [
            JsonAssetDataDriver::class,
            static fn (string $content): array => json_decode($content, true, flags: JSON_THROW_ON_ERROR),
        ];

        yield 'YAML' => [
            YamlAssetDataDriver::class,
            static fn (string $content): array => Yaml::parse($content),
        ];
    }
}
