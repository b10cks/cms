<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\AssetCollectionController;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetCollection;
use App\Models\Space\AssetCollectionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(AssetCollectionController::class)]
class AssetCollectionTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $user;

    protected Space $space;

    protected Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => [
                'root' => storage_path("app/spaces/{$this->space->id}"),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        Sanctum::actingAs($this->user);
        LaravelStorage::fake($this->storage->id);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    private function baseUrl(): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/asset-collections";
    }

    private function createAsset(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'storage_id' => $this->storage->id,
            'folder_id' => null,
            'tags' => null,
        ], $attributes));
    }

    #[Test]
    public function user_can_create_a_manual_collection(): void
    {
        $response = $this->postJson($this->baseUrl(), [
            'name' => 'Brand Photos',
            'description' => 'Approved brand photography',
            'color' => '#ff0000',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Brand Photos')
            ->assertJsonPath('data.type', 'manual')
            ->assertJsonPath('data.assets_count', 0);
    }

    #[Test]
    public function creating_a_smart_collection_requires_rules(): void
    {
        $this->postJson($this->baseUrl(), [
            'name' => 'All Images',
            'type' => 'smart',
        ])->assertStatus(422);
    }

    #[Test]
    public function creating_a_smart_collection_rejects_invalid_rules(): void
    {
        $this->postJson($this->baseUrl(), [
            'name' => 'Bad Rules',
            'type' => 'smart',
            'rules' => [
                'match' => 'all',
                'conditions' => [
                    ['field' => 'nonsense; DROP TABLE assets', 'operator' => 'equals', 'value' => 'x'],
                ],
            ],
        ])->assertStatus(422);

        $this->postJson($this->baseUrl(), [
            'name' => 'Bad Operator',
            'type' => 'smart',
            'rules' => [
                'match' => 'all',
                'conditions' => [
                    ['field' => 'filename', 'operator' => 'gt', 'value' => 'x'],
                ],
            ],
        ])->assertStatus(422);
    }

    #[Test]
    public function user_can_create_and_show_a_smart_collection_with_computed_count(): void
    {
        $this->createAsset(['mime_type' => 'image/jpeg', 'extension' => 'jpg']);
        $this->createAsset(['mime_type' => 'image/png', 'extension' => 'png']);
        $this->createAsset(['mime_type' => 'application/pdf', 'extension' => 'pdf']);

        $response = $this->postJson($this->baseUrl(), [
            'name' => 'All Images',
            'type' => 'smart',
            'rules' => [
                'match' => 'all',
                'conditions' => [
                    ['field' => 'mime_type', 'operator' => 'prefix', 'value' => 'image/'],
                ],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.assets_count', 2);

        $this->getJson($this->baseUrl().'/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.type', 'smart')
            ->assertJsonPath('data.assets_count', 2);
    }

    #[Test]
    public function index_lists_collections_with_manual_counts_only(): void
    {
        $manual = AssetCollection::factory()->create(['name' => 'Manual']);
        AssetCollection::factory()->smart([
            'match' => 'all',
            'conditions' => [
                ['field' => 'mime_type', 'operator' => 'prefix', 'value' => 'image/'],
            ],
        ])->create(['name' => 'Smart']);

        $asset = $this->createAsset();
        AssetCollectionItem::query()->create([
            'collection_id' => $manual->id,
            'asset_id' => $asset->id,
            'position' => 0,
        ]);

        $response = $this->getJson($this->baseUrl().'?sort=name');

        $response->assertOk()->assertJsonCount(2, 'data');

        $collections = collect($response->json('data'))->keyBy('name');
        $this->assertSame(1, $collections['Manual']['assets_count']);
        $this->assertNull($collections['Smart']['assets_count']);
    }

    #[Test]
    public function user_can_update_a_collection(): void
    {
        $collection = AssetCollection::factory()->create();

        $this->patchJson($this->baseUrl().'/'.$collection->id, [
            'name' => 'Renamed',
            'description' => 'Updated description',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.description', 'Updated description');
    }

    #[Test]
    public function deleting_a_collection_keeps_its_items_for_restore(): void
    {
        $collection = AssetCollection::factory()->create();
        $asset = $this->createAsset();

        AssetCollectionItem::query()->create([
            'collection_id' => $collection->id,
            'asset_id' => $asset->id,
            'position' => 0,
        ]);

        $this->deleteJson($this->baseUrl().'/'.$collection->id)->assertNoContent();

        // Soft-deleted only: the items stay so restoring the collection
        // restores its membership.
        $this->assertSoftDeleted($collection);
        $this->assertSame(1, AssetCollectionItem::query()->where('collection_id', $collection->id)->count());
    }

    #[Test]
    public function user_can_add_assets_to_a_manual_collection(): void
    {
        $collection = AssetCollection::factory()->create();
        $first = $this->createAsset();
        $second = $this->createAsset();

        $this->postJson("{$this->baseUrl()}/{$collection->id}/assets", [
            'asset_ids' => [$first->id, $second->id],
        ])->assertNoContent();

        // Re-adding an existing asset is a no-op, not an error.
        $this->postJson("{$this->baseUrl()}/{$collection->id}/assets", [
            'asset_ids' => [$first->id],
        ])->assertNoContent();

        $items = AssetCollectionItem::query()
            ->where('collection_id', $collection->id)
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $items);
        $this->assertSame([$first->id, $second->id], $items->pluck('asset_id')->all());
        $this->assertSame($this->user->id, $items->first()->added_by_id);
    }

    #[Test]
    public function manual_item_mutations_are_rejected_for_smart_collections(): void
    {
        $collection = AssetCollection::factory()->smart([
            'match' => 'all',
            'conditions' => [
                ['field' => 'mime_type', 'operator' => 'prefix', 'value' => 'image/'],
            ],
        ])->create();
        $asset = $this->createAsset();

        $payload = ['asset_ids' => [$asset->id]];

        $this->postJson("{$this->baseUrl()}/{$collection->id}/assets", $payload)->assertStatus(422);
        $this->deleteJson("{$this->baseUrl()}/{$collection->id}/assets", $payload)->assertStatus(422);
        $this->patchJson("{$this->baseUrl()}/{$collection->id}/assets/order", $payload)->assertStatus(422);
    }

    #[Test]
    public function user_can_remove_assets_from_a_manual_collection(): void
    {
        $collection = AssetCollection::factory()->create();
        $keep = $this->createAsset();
        $remove = $this->createAsset();

        foreach ([$keep, $remove] as $position => $asset) {
            AssetCollectionItem::query()->create([
                'collection_id' => $collection->id,
                'asset_id' => $asset->id,
                'position' => $position,
            ]);
        }

        $this->deleteJson("{$this->baseUrl()}/{$collection->id}/assets", [
            'asset_ids' => [$remove->id],
        ])->assertNoContent();

        $remaining = AssetCollectionItem::query()->where('collection_id', $collection->id)->pluck('asset_id');
        $this->assertSame([$keep->id], $remaining->all());
    }

    #[Test]
    public function user_can_reorder_a_manual_collection(): void
    {
        $collection = AssetCollection::factory()->create();
        $assets = collect([$this->createAsset(), $this->createAsset(), $this->createAsset()]);

        $assets->each(function (Asset $asset, int $position) use ($collection) {
            AssetCollectionItem::query()->create([
                'collection_id' => $collection->id,
                'asset_id' => $asset->id,
                'position' => $position,
            ]);
        });

        $reversed = $assets->reverse()->pluck('id')->values()->all();

        $this->patchJson("{$this->baseUrl()}/{$collection->id}/assets/order", [
            'asset_ids' => $reversed,
        ])->assertNoContent();

        $ordered = AssetCollectionItem::query()
            ->where('collection_id', $collection->id)
            ->orderBy('position')
            ->pluck('asset_id')
            ->all();

        $this->assertSame($reversed, $ordered);
    }

    #[Test]
    public function manual_collection_assets_are_listed_in_position_order(): void
    {
        $collection = AssetCollection::factory()->create();
        $first = $this->createAsset();
        $second = $this->createAsset();
        $outside = $this->createAsset();

        AssetCollectionItem::query()->create([
            'collection_id' => $collection->id,
            'asset_id' => $second->id,
            'position' => 0,
        ]);
        AssetCollectionItem::query()->create([
            'collection_id' => $collection->id,
            'asset_id' => $first->id,
            'position' => 1,
        ]);

        $response = $this->getJson("{$this->baseUrl()}/{$collection->id}/assets");

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$second->id, $first->id], array_column($response->json('data'), 'id'));
        $this->assertNotContains($outside->id, array_column($response->json('data'), 'id'));
    }

    #[Test]
    public function smart_collection_assets_are_resolved_from_rules(): void
    {
        $matching = $this->createAsset(['mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 5000]);
        $tooSmall = $this->createAsset(['mime_type' => 'image/png', 'extension' => 'png', 'size' => 10]);
        $wrongType = $this->createAsset(['mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 5000]);

        $collection = AssetCollection::factory()->smart([
            'match' => 'all',
            'conditions' => [
                ['field' => 'mime_type', 'operator' => 'prefix', 'value' => 'image/'],
                ['field' => 'size', 'operator' => 'gte', 'value' => 1000],
            ],
        ])->create();

        $response = $this->getJson("{$this->baseUrl()}/{$collection->id}/assets");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($matching->id, $response->json('data.0.id'));
        $this->assertNotContains($tooSmall->id, array_column($response->json('data'), 'id'));
        $this->assertNotContains($wrongType->id, array_column($response->json('data'), 'id'));
    }

    #[Test]
    public function smart_collection_supports_match_any_and_orientation(): void
    {
        $landscape = $this->createAsset([
            'mime_type' => 'image/jpeg',
            'metadata' => ['width' => 1920, 'height' => 1080],
            'tags' => null,
        ]);
        $portrait = $this->createAsset([
            'mime_type' => 'image/jpeg',
            'metadata' => ['width' => 1080, 'height' => 1920],
            'tags' => ['some-tag'],
        ]);

        $collection = AssetCollection::factory()->smart([
            'match' => 'any',
            'conditions' => [
                ['field' => 'orientation', 'operator' => 'equals', 'value' => 'landscape'],
                ['field' => 'untagged', 'operator' => 'equals', 'value' => false],
            ],
        ])->create();

        $response = $this->getJson("{$this->baseUrl()}/{$collection->id}/assets");

        $response->assertOk()->assertJsonCount(2, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($landscape->id, $ids);
        $this->assertContains($portrait->id, $ids);
    }

    #[Test]
    public function users_without_membership_cannot_access_collections(): void
    {
        AssetCollection::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->baseUrl())->assertForbidden();
        $this->postJson($this->baseUrl(), ['name' => 'Nope'])->assertForbidden();
    }
}
