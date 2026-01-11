<?php

namespace Database\Factories\Space;

use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        $types = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'application/pdf' => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'text/plain' => ['txt'],
            'text/csv' => ['csv'],
            'video/mp4' => ['mp4'],
            'audio/mpeg' => ['mp3'],
        ];

        // Randomly select a MIME type
        $mimeType = $this->faker->randomElement(array_keys($types));
        $extensions = $types[$mimeType];
        $extension = $this->faker->randomElement($extensions);

        // Generate a random filename
        $filename = $this->faker->slug(3);

        // Determine file size - varies by type
        $fileSizeRanges = [
            'image/' => [50000, 5000000],        // 50KB to 5MB
            'application/' => [10000, 10000000], // 10KB to 10MB
            'text/' => [1000, 500000],           // 1KB to 500KB
            'video/' => [500000, 20000000],      // 500KB to 20MB
            'audio/' => [100000, 10000000],      // 100KB to 10MB
        ];

        // Find the appropriate size range based on mime type prefix
        $sizeRange = [1000, 1000000]; // Default: 1KB to 1MB
        foreach ($fileSizeRanges as $prefix => $range) {
            if (str_starts_with($mimeType, $prefix)) {
                $sizeRange = $range;
                break;
            }
        }

        $size = $this->faker->numberBetween($sizeRange[0], $sizeRange[1]);

        // Generate path in the format space_id/asset_id/filename.extension
        // We'll use "fake" placeholders for space_id and asset_id
        $path = 'fake-space-id/fake-asset-id/' . $filename . '.' . $extension;

        return [
            'external_id' => Str::uuid(),
            'filename' => $filename,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'path' => $path,
            'storage_id' => Storage::factory(),
            'folder_id' => $this->faker->boolean(30) ? AssetFolder::factory() : null,
            'size' => $size,
            'metadata' => [
                'description' => $this->faker->sentence(),
                'title' => $this->faker->words(3, true),
                'author' => $this->faker->name(),
                'created_with' => $this->faker->randomElement(['Photoshop', 'Illustrator', 'Word', 'Excel', 'iPhone', 'Android']),
            ],
            'tags' => $this->faker->boolean(70) ? $this->faker->words($this->faker->numberBetween(1, 5)) : null,
        ];
    }

    /**
     * Configure the model after creation to update the path with real IDs
     */
    public function configure()
    {
        return $this->afterCreating(function (Asset $asset) {
            // Update the path with real IDs
            $path = str_replace(
                ['fake-space-id', 'fake-asset-id'],
                [$asset->storage->space_id, $asset->id],
                $asset->path
            );

            $asset->path = $path;
            $asset->save();
        });
    }

    /**
     * Create an image asset
     */
    public function image(): self
    {
        return $this->state(function () {
            $extension = $this->faker->randomElement(['jpg', 'png', 'gif']);
            $mimeType = [
                'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
            ][$extension];

            return [
                'extension' => $extension,
                'mime_type' => $mimeType,
                'metadata' => [
                    'description' => $this->faker->sentence(),
                    'width' => $this->faker->numberBetween(800, 3000),
                    'height' => $this->faker->numberBetween(600, 2000),
                    'alt' => $this->faker->sentence(4),
                ],
            ];
        });
    }

    /**
     * Create a document asset
     */
    public function document(): self
    {
        return $this->state(function () {
            $docTypes = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];

            $extension = $this->faker->randomElement(array_keys($docTypes));
            $mimeType = $docTypes[$extension];

            return [
                'extension' => $extension,
                'mime_type' => $mimeType,
                'metadata' => [
                    'description' => $this->faker->sentence(),
                    'author' => $this->faker->name(),
                    'created_at' => $this->faker->dateTimeThisYear()->format('Y-m-d H:i:s'),
                    'page_count' => $this->faker->numberBetween(1, 50),
                ],
            ];
        });
    }

    /**
     * Create a video asset
     */
    public function video(): self
    {
        return $this->state(function () {
            return [
                'extension' => 'mp4',
                'mime_type' => 'video/mp4',
                'size' => $this->faker->numberBetween(1000000, 50000000), // 1MB to 50MB
                'metadata' => [
                    'description' => $this->faker->sentence(),
                    'duration' => $this->faker->numberBetween(5, 600), // 5s to 10min
                    'width' => $this->faker->randomElement([640, 1280, 1920, 3840]),
                    'height' => $this->faker->randomElement([360, 720, 1080, 2160]),
                    'fps' => $this->faker->randomElement([24, 25, 30, 60]),
                ],
            ];
        });
    }

    /**
     * Create an audio asset
     */
    public function audio(): self
    {
        return $this->state(function () {
            return [
                'extension' => 'mp3',
                'mime_type' => 'audio/mpeg',
                'size' => $this->faker->numberBetween(500000, 15000000), // 500KB to 15MB
                'metadata' => [
                    'description' => $this->faker->sentence(),
                    'duration' => $this->faker->numberBetween(30, 600), // 30s to 10min
                    'artist' => $this->faker->name(),
                    'album' => $this->faker->words(3, true),
                    'year' => $this->faker->year(),
                    'genre' => $this->faker->randomElement(['Rock', 'Pop', 'Jazz', 'Classical', 'Electronic', 'Hip Hop']),
                ],
            ];
        });
    }
}
