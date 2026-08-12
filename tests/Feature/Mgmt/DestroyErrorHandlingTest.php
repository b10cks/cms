<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\BlockTagController;
use App\Http\Controllers\Mgmt\RedirectController;
use App\Models\Management\Space;
use App\Models\Space\BlockTag;
use App\Models\Space\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The destroy endpoints deliberately carry no try/catch: a delete that throws
 * is reported and rendered by the framework exception handler (500) instead of
 * a hand-rolled Log::error + JSON payload.
 */
#[CoversClass(BlockTagController::class)]
#[CoversClass(RedirectController::class)]
class DestroyErrorHandlingTest extends TestCase
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
        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    #[Test]
    public function deleting_a_block_tag_returns_no_content(): void
    {
        BlockTag::forceCreate(['name' => 'hero']);

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/block-tags/hero")
            ->assertStatus(204);

        $this->assertDatabaseMissing('block_tags', ['name' => 'hero']);
    }

    #[Test]
    public function deleting_a_redirect_returns_no_content(): void
    {
        $redirect = Redirect::forceCreate([
            'source' => '/old-path',
            'target' => '/new-path',
            'status_code' => 301,
        ]);

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/redirects/{$redirect->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('redirects', ['id' => $redirect->id]);
    }

    #[Test]
    public function a_failing_delete_still_yields_a_server_error(): void
    {
        $redirect = Redirect::forceCreate([
            'source' => '/boom',
            'target' => '/new-path',
            'status_code' => 301,
        ]);

        Redirect::deleting(function (): void {
            throw new \RuntimeException('storage exploded');
        });

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/redirects/{$redirect->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('redirects', ['id' => $redirect->id]);
    }
}
