<?php

namespace Tests\Unit\Models\Space;

use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class PurifiedAssetAttributesTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSpaceTesting(Space::factory()->create());
    }

    #[Test]
    public function asset_filename_is_purified_on_write_and_read_as_stored(): void
    {
        $storage = Storage::factory()->create(['driver' => 'local', 'state' => 'live']);
        $asset = Asset::factory()->create([
            'storage_id' => $storage->id,
            'filename' => 'photo<script>alert(1)</script>',
        ]);

        $this->assertSame('photo', $asset->getRawOriginal('filename'));

        Asset::query()->whereKey($asset->id)->toBase()->update(['filename' => 'raw<b>name</b>']);

        $this->assertSame('raw<b>name</b>', $asset->fresh()->filename);
    }

    #[Test]
    public function folder_name_and_description_are_purified_on_write_and_read_as_stored(): void
    {
        $folder = AssetFolder::factory()->create([
            'name' => 'Media<script>alert(1)</script>',
            'description' => '<p onclick="x()">Hi</p><script>alert(1)</script>',
        ]);

        $this->assertSame('Media', $folder->getRawOriginal('name'));
        $this->assertSame('<p>Hi</p>', $folder->getRawOriginal('description'));

        AssetFolder::query()->whereKey($folder->id)->toBase()->update(['name' => 'raw<b>name</b>']);

        $this->assertSame('raw<b>name</b>', $folder->fresh()->name);
    }

    #[Test]
    public function purify_row_applies_write_mutators_to_raw_rows_only(): void
    {
        $row = AssetFolder::purifyRow([
            'name' => 'Media<script>alert(1)</script>',
            'icon' => '<b>folder</b>',
        ]);

        $this->assertSame(['name' => 'Media', 'icon' => '<b>folder</b>'], $row);
    }
}
