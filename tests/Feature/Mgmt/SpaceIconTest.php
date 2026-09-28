<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\SpaceIconController;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(SpaceIconController::class)]
class SpaceIconTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->space = Space::factory()->withLive()->create();
        $this->admin = User::factory()->create();
        $this->assignSpaceRole($this->space, $this->admin, 'admin');
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function an_uploaded_icon_path_is_stored_on_the_space(): void
    {
        $this->actingAs($this->admin)
            ->post(route('mgmt.spaces.icon', $this->space), [
                'icon' => UploadedFile::fake()->image('icon.png', 10, 10),
            ])
            ->assertSuccessful();

        $stored = $this->space->fresh()->icon;

        $this->assertStringStartsWith("spaces/icons/{$this->space->id}_", $stored);
        Storage::disk()->assertExists($stored);
    }

    /**
     * The next upload deletes whatever path `icon` holds, so a space update
     * must not be able to point it at another file.
     */
    #[Test]
    public function a_space_update_cannot_repoint_the_icon_path(): void
    {
        Storage::disk()->put('spaces/icons/other-space.png', 'not yours');
        $before = $this->space->icon;

        $this->actingAs($this->admin)
            ->putJson(route('mgmt.spaces.update', $this->space), [
                'icon' => 'spaces/icons/other-space.png',
            ])
            ->assertOk();

        $this->assertSame($before, $this->space->fresh()->icon);
    }
}
