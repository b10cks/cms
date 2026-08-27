<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\EnsureAssetFolderPathsController;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\AssetFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(EnsureAssetFolderPathsController::class)]
class AssetFolderEnsurePathsTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->user, 'owner');

        Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'driver' => 'local',
            'state' => 'live',
        ]);

        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
    }

    private function ensurePaths(array $payload)
    {
        return $this->postJson(
            "/mgmt/v1/spaces/{$this->space->id}/asset-folders/ensure-paths",
            $payload,
        );
    }

    #[Test]
    public function it_creates_a_nested_path()
    {
        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['Brand/Logos/Dark'],
        ]);

        $response->assertOk();

        $brand = AssetFolder::query()->where('name', 'Brand')->whereNull('parent_id')->firstOrFail();
        $logos = AssetFolder::query()->where('name', 'Logos')->where('parent_id', $brand->id)->firstOrFail();
        $dark = AssetFolder::query()->where('name', 'Dark')->where('parent_id', $logos->id)->firstOrFail();

        $response->assertJsonPath('paths.Brand/Logos/Dark', $dark->id);
        $response->assertJsonCount(3, 'folders');
        $response->assertJsonPath('renamed', []);
    }

    #[Test]
    public function it_resolves_paths_under_a_given_parent()
    {
        $parent = AssetFolder::factory()->create(['name' => 'Existing']);

        $response = $this->ensurePaths([
            'parent_id' => $parent->id,
            'paths' => ['Photos'],
        ]);

        $response->assertOk();

        $photos = AssetFolder::query()->where('name', 'Photos')->firstOrFail();
        $this->assertSame($parent->id, $photos->parent_id);
        $response->assertJsonPath('paths.Photos', $photos->id);
    }

    #[Test]
    public function it_merges_into_an_existing_folder()
    {
        $existing = AssetFolder::factory()->create(['name' => 'Brand']);

        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['Brand/Logos'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('paths.Brand/Logos', AssetFolder::query()->where('name', 'Logos')->firstOrFail()->id);

        $this->assertSame(1, AssetFolder::query()->where('name', 'Brand')->count());
        $this->assertSame($existing->id, AssetFolder::query()->where('name', 'Logos')->firstOrFail()->parent_id);
    }

    #[Test]
    public function it_merges_case_insensitively()
    {
        $existing = AssetFolder::factory()->create(['name' => 'brand']);

        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['BRAND/Logos'],
        ]);

        $response->assertOk();

        $this->assertSame(2, AssetFolder::query()->count());
        $this->assertSame(
            $existing->id,
            AssetFolder::query()->where('name', 'Logos')->firstOrFail()->parent_id,
        );
    }

    #[Test]
    public function it_ignores_soft_deleted_folders_instead_of_restoring_them()
    {
        $deleted = AssetFolder::factory()->create(['name' => 'Brand']);
        $deleted->delete();

        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['Brand'],
        ]);

        $response->assertOk();

        $fresh = AssetFolder::query()->where('name', 'Brand')->firstOrFail();
        $this->assertNotSame($deleted->id, $fresh->id);
        $this->assertSoftDeleted('asset_folders', ['id' => $deleted->id]);
    }

    #[Test]
    public function it_rejects_users_without_the_folder_manage_ability()
    {
        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        Sanctum::actingAs($viewer);

        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['Brand'],
        ]);

        $response->assertForbidden();
        $this->assertSame(0, AssetFolder::query()->count());
    }

    #[Test]
    public function it_truncates_names_past_the_column_length_and_reports_the_change()
    {
        $long = str_repeat('a', 150);

        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => [$long],
        ]);

        $response->assertOk();

        $folder = AssetFolder::query()->firstOrFail();
        $this->assertSame(str_repeat('a', 100), $folder->name);
        $response->assertJsonPath('renamed.0.from', $long);
        $response->assertJsonPath('renamed.0.to', str_repeat('a', 100));
    }

    #[Test]
    public function it_falls_back_to_a_placeholder_when_purification_empties_a_name()
    {
        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['<br><br>/Logos'],
        ]);

        $response->assertOk();

        $placeholder = AssetFolder::query()->where('name', 'folder')->firstOrFail();
        $this->assertNull($placeholder->parent_id);
        $this->assertSame(
            $placeholder->id,
            AssetFolder::query()->where('name', 'Logos')->firstOrFail()->parent_id,
        );
        $response->assertJsonPath('renamed.0.to', 'folder');
    }

    #[Test]
    public function it_creates_a_placeholder_folder_for_a_whitespace_only_segment()
    {
        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['Brand/   '],
        ]);

        $response->assertOk();

        $brand = AssetFolder::query()->where('name', 'Brand')->firstOrFail();
        $placeholder = AssetFolder::query()->where('name', 'folder')->firstOrFail();

        // The folder exists on disk, so it becomes a real folder rather than
        // collapsing into its parent and stranding the files it holds.
        $this->assertSame($brand->id, $placeholder->parent_id);
        $this->assertSame($placeholder->id, $response->json('paths')['Brand/   ']);
        $response->assertJsonPath('renamed.0.from', '   ');
        $response->assertJsonPath('renamed.0.to', 'folder');
    }

    #[Test]
    public function it_merges_two_casings_of_the_same_new_path_within_one_payload()
    {
        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ['Brand/Logos', 'BRAND/Icons', 'brand'],
        ]);

        $response->assertOk();

        $this->assertSame(1, AssetFolder::query()->whereNull('parent_id')->count());
        $brand = AssetFolder::query()->whereNull('parent_id')->firstOrFail();

        $paths = $response->json('paths');
        $this->assertSame($brand->id, $paths['brand']);
        $this->assertSame(
            $brand->id,
            AssetFolder::query()->where('name', 'Logos')->firstOrFail()->parent_id,
        );
        $this->assertSame(
            $brand->id,
            AssetFolder::query()->where('name', 'Icons')->firstOrFail()->parent_id,
        );
    }

    #[Test]
    public function it_merges_a_decomposed_name_into_its_composed_twin()
    {
        if (!class_exists(\Normalizer::class)) {
            $this->markTestSkipped('ext-intl is not installed, names are compared as they arrive.');
        }

        $existing = AssetFolder::factory()->create(['name' => "Caf\u{e9}"]);

        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => ["Cafe\u{301}/Menus"],
        ]);

        $response->assertOk();

        $this->assertSame(2, AssetFolder::query()->count());
        $this->assertSame(
            $existing->id,
            AssetFolder::query()->where('name', 'Menus')->firstOrFail()->parent_id,
        );
    }

    #[Test]
    public function it_bounds_the_length_of_a_whitespace_only_path()
    {
        // Laravel skips non-implicit rules on a blank string, so `min`/`max`
        // would never see this one. A quarter megabyte of spaces per path is a
        // cheap way to buy 2000 purifier passes inside the lock.
        $response = $this->ensurePaths([
            'parent_id' => null,
            'paths' => [str_repeat(' ', 9000)],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paths.0');
        $this->assertSame(0, AssetFolder::query()->count());
    }

    #[Test]
    public function it_rejects_an_empty_path()
    {
        $response = $this->ensurePaths(['parent_id' => null, 'paths' => ['']]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paths.0');
    }

    #[Test]
    public function it_rejects_a_path_that_is_not_a_string()
    {
        $response = $this->ensurePaths(['parent_id' => null, 'paths' => [['Brand']]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paths.0');
    }

    #[Test]
    public function it_rejects_more_folder_levels_than_a_single_upload_may_mirror()
    {
        // Few paths, but each one a deep chain: the array size does not bound
        // the folders this would create, the segment count does.
        $paths = array_map(
            static fn (int $index): string => implode('/', array_fill(0, 300, "s{$index}")),
            range(1, 10),
        );

        $response = $this->ensurePaths(['parent_id' => null, 'paths' => $paths]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paths');
        $this->assertSame(0, AssetFolder::query()->count());
    }

    #[Test]
    public function it_rejects_more_paths_than_a_single_upload_may_mirror()
    {
        $paths = array_map(static fn (int $index): string => "Folder {$index}", range(1, 2001));

        $response = $this->ensurePaths(['parent_id' => null, 'paths' => $paths]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paths');
        $this->assertSame(0, AssetFolder::query()->count());
    }

    #[Test]
    public function it_is_idempotent_for_the_same_payload()
    {
        $payload = [
            'parent_id' => null,
            'paths' => ['Brand/Logos', 'Brand/Photos', 'Brand'],
        ];

        $first = $this->ensurePaths($payload);
        $second = $this->ensurePaths($payload);

        $first->assertOk();
        $second->assertOk();

        $this->assertSame(3, AssetFolder::query()->count());
        $this->assertSame($first->json('paths'), $second->json('paths'));
    }
}
