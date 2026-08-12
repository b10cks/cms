<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\User;
use App\Policies\BlockPolicy;
use App\Policies\SpaceResourcePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[CoversClass(Controller::class)]
#[CoversClass(SpaceResourcePolicy::class)]
class SpaceAuthorizationHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
    }

    /**
     * Controller::authorizeSpace() is protected, so expose it through a tiny
     * subclass rather than reaching in with reflection.
     */
    protected function controller(): Controller
    {
        return new class extends Controller
        {
            public function check(Space $space, string $ability): void
            {
                $this->authorizeSpace($space, $ability);
            }
        };
    }

    #[Test]
    public function authorize_space_passes_when_the_user_holds_the_ability(): void
    {
        $user = User::factory()->create();
        $this->assignSpaceRole($this->space, $user, 'owner');
        $this->actingAs($user);

        $this->controller()->check($this->space, 'blocks.manage');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function authorize_space_aborts_with_403_when_the_ability_is_missing(): void
    {
        $user = User::factory()->create();
        $this->assignSpaceRole($this->space, $user, 'editor');
        $this->actingAs($user);

        try {
            $this->controller()->check($this->space, 'blocks.manage');
            $this->fail('Expected a 403 to be thrown.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function authorize_space_aborts_with_403_for_a_user_without_any_space_role(): void
    {
        $this->actingAs(User::factory()->create());

        try {
            $this->controller()->check($this->space, 'blocks.view');
            $this->fail('Expected a 403 to be thrown.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function space_resource_policy_maps_read_abilities_to_resource_view(): void
    {
        // `editor` holds blocks.view but not blocks.manage, so it isolates the
        // view/manage split of the shared mapping.
        $user = User::factory()->create();
        $this->assignSpaceRole($this->space, $user, 'editor');

        $policy = new BlockPolicy;
        $block = new Block;

        $this->assertTrue($policy->viewAny($user, $this->space));
        $this->assertTrue($policy->view($user, $block, $this->space));

        $this->assertFalse($policy->create($user, $this->space));
        $this->assertFalse($policy->update($user, $block, $this->space));
        $this->assertFalse($policy->delete($user, $block, $this->space));
    }

    #[Test]
    public function space_resource_policy_maps_write_abilities_to_resource_manage(): void
    {
        $user = User::factory()->create();
        $this->assignSpaceRole($this->space, $user, 'owner');

        $policy = new BlockPolicy;
        $block = new Block;

        $this->assertTrue($policy->viewAny($user, $this->space));
        $this->assertTrue($policy->view($user, $block, $this->space));
        $this->assertTrue($policy->create($user, $this->space));
        $this->assertTrue($policy->update($user, $block, $this->space));
        $this->assertTrue($policy->delete($user, $block, $this->space));
    }

    #[Test]
    public function space_resource_policy_denies_everything_without_a_space_role(): void
    {
        $user = User::factory()->create();

        $policy = new BlockPolicy;
        $block = new Block;

        $this->assertFalse($policy->viewAny($user, $this->space));
        $this->assertFalse($policy->view($user, $block, $this->space));
        $this->assertFalse($policy->create($user, $this->space));
        $this->assertFalse($policy->update($user, $block, $this->space));
        $this->assertFalse($policy->delete($user, $block, $this->space));
    }
}
