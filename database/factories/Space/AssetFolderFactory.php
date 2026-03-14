<?php

namespace Database\Factories\Space;

use App\Models\Management\Storage;
use App\Models\Space\AssetFolder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AssetFolderFactory extends Factory
{
    protected $model = AssetFolder::class;

    public function definition(): array
    {
        $folderTypes = [
            'Images' => ['image', '#4ade80'], // Green
            'Documents' => ['file-text', '#f97316'], // Orange
            'Videos' => ['video', '#3b82f6'], // Blue
            'Audio' => ['music', '#8b5cf6'], // Purple
            'Downloads' => ['download', '#64748b'], // Slate
            'Archives' => ['archive', '#f59e0b'], // Amber
            'Favorites' => ['star', '#ec4899'], // Pink
            'Templates' => ['copy', '#0ea5e9'], // Sky
        ];

        // Pick a random folder type
        $folderName = $this->faker->randomElement(array_keys($folderTypes));
        [$icon, $color] = $folderTypes[$folderName];

        // Alternatively, generate a random folder name
        if ($this->faker->boolean(70)) {
            $folderName = $this->faker->unique()->words(mt_rand(1, 3), true);
        }

        return [
            'external_id' => Str::uuid(),
            'name' => ucfirst($folderName),
            'description' => $this->faker->boolean(70) ? $this->faker->sentence() : null,
            'icon' => $icon,
            'color' => $color,
            'parent_id' => null, // By default, no parent
            'settings' => [],
        ];
    }

    /**
     * Create a folder with a parent
     */
    public function withParent(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'parent_id' => AssetFolder::factory(),
            ];
        });
    }

    /**
     * Create a folder for images
     */
    public function forImages(): self
    {
        return $this->state([
            'name' => 'Images',
            'icon' => 'image',
            'color' => '#4ade80',
            'description' => 'Folder for image files',
        ]);
    }

    /**
     * Create a folder for documents
     */
    public function forDocuments(): self
    {
        return $this->state([
            'name' => 'Documents',
            'icon' => 'file-text',
            'color' => '#f97316',
            'description' => 'Folder for document files',
        ]);
    }

    /**
     * Create a folder for videos
     */
    public function forVideos(): self
    {
        return $this->state([
            'name' => 'Videos',
            'icon' => 'video',
            'color' => '#3b82f6',
            'description' => 'Folder for video files',
        ]);
    }

    /**
     * Create a folder for audio files
     */
    public function forAudio(): self
    {
        return $this->state([
            'name' => 'Audio',
            'icon' => 'music',
            'color' => '#8b5cf6',
            'description' => 'Folder for audio files',
        ]);
    }
}
