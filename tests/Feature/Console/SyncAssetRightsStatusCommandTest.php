<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SyncAssetRightsStatusCommand;
use App\Enums\AssetRightsStatus;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(SyncAssetRightsStatusCommand::class)]
class SyncAssetRightsStatusCommandTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected Space $space;

    protected Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function it_marks_assets_with_a_past_expiry_date_as_expired(): void
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
        ]);
        Asset::query()->whereKey($asset->id)->update([
            'license_expires_at' => now()->subDay(),
            'rights_status' => AssetRightsStatus::UNRESTRICTED->value,
        ]);

        $this->artisan('assets:sync-rights-status')->assertExitCode(0);

        $this->assertSame(AssetRightsStatus::EXPIRED->value, $asset->fresh()->rights_status);
    }

    #[Test]
    public function it_marks_assets_with_a_future_expiry_date_as_restricted(): void
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
        ]);
        Asset::query()->whereKey($asset->id)->update([
            'license_expires_at' => now()->addWeek(),
            'rights_status' => AssetRightsStatus::UNRESTRICTED->value,
        ]);

        $this->artisan('assets:sync-rights-status')->assertExitCode(0);

        $this->assertSame(AssetRightsStatus::RESTRICTED->value, $asset->fresh()->rights_status);
    }

    #[Test]
    public function it_marks_assets_without_an_expiry_date_as_unrestricted(): void
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
        ]);
        Asset::query()->whereKey($asset->id)->update([
            'license_expires_at' => null,
            'rights_status' => AssetRightsStatus::RESTRICTED->value,
        ]);

        $this->artisan('assets:sync-rights-status')->assertExitCode(0);

        $this->assertSame(AssetRightsStatus::UNRESTRICTED->value, $asset->fresh()->rights_status);
    }

    #[Test]
    public function dry_run_does_not_modify_assets(): void
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
        ]);
        Asset::query()->whereKey($asset->id)->update([
            'license_expires_at' => now()->subDay(),
            'rights_status' => AssetRightsStatus::UNRESTRICTED->value,
        ]);

        $this->artisan('assets:sync-rights-status', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(AssetRightsStatus::UNRESTRICTED->value, $asset->fresh()->rights_status);
    }
}
