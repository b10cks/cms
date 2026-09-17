<?php

namespace Tests\Feature\Mgmt;

use App\Events\Space\SpaceModelChanged;
use App\Exceptions\DuplicateAssetException;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Services\Storage\AssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AssetStoreTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => ['root' => storage_path('framework/testing/asset-store-'.$this->space->id)],
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    /**
     * The storage path embeds the asset id. Saving before the path was known
     * broadcast a `created` asset without a URL and logged an error per upload.
     */
    #[Test]
    public function an_upload_is_inserted_once_with_its_path(): void
    {
        Event::fake([SpaceModelChanged::class]);

        $asset = $this->store('hello.txt', 'hello');

        $this->assertSame("{$this->space->id}/{$asset->id}/hello.txt", $asset->path);

        $broadcasts = Event::dispatched(SpaceModelChanged::class);
        $this->assertCount(1, $broadcasts);

        $event = $broadcasts[0][0];
        $this->assertSame('asset:created', $event->broadcastAs());
        $this->assertNotNull($event->broadcastWith()['data']['url']);
    }

    #[Test]
    public function a_rejected_duplicate_leaves_no_row(): void
    {
        $this->store('first.txt', 'same bytes');

        try {
            $this->store('second.txt', 'same bytes');
            $this->fail('Expected a duplicate rejection.');
        } catch (DuplicateAssetException) {
            // expected
        }

        $this->assertSame(1, Asset::query()->count());
    }

    private function store(string $name, string $content): Asset
    {
        return app(AssetService::class)->storeAsset(
            $this->space,
            UploadedFile::fake()->createWithContent($name, $content),
            (object) [],
            (object) [],
        );
    }
}
