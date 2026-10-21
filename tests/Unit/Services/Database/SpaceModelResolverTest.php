<?php

namespace Tests\Unit\Services\Database;

use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Models\Space\Redirect;
use App\Services\Database\ConnectionFactory;
use App\Services\Database\DatabaseConnectionResolver;
use App\Services\Database\SpaceModelResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The resolver short-circuits to the default connection under
 * runningUnitTests(), which hides how often Eloquent asks it for a
 * connection. These tests run it as production does and count how often it
 * goes down to the factory (decrypt, merge, register).
 */
#[CoversClass(SpaceModelResolver::class)]
class SpaceModelResolverTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    /** @var ConnectionFactory&object{made: list<string>, released: list<string>} */
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runSpaceMigrations();
        $this->stubManagementAuditService();

        $this->factory = new class(new DatabaseConnectionResolver()) extends ConnectionFactory
        {
            /** @var list<string> */
            public array $made = [];

            /** @var list<string> */
            public array $released = [];

            public function make(SpaceConnection $connection): ConnectionInterface
            {
                $this->made[] = $connection->id;

                return DB::connection();
            }

            public function release(string $name): void
            {
                $this->released[] = $name;
            }
        };

        $this->app->instance(ConnectionFactory::class, $this->factory);
        $this->app->instance(SpaceModelResolver::class, new SpaceModelResolver());
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    #[Test]
    public function it_resolves_once_for_every_row_read_in_a_request(): void
    {
        [$space, $connection] = $this->spaceWithConnection();
        Redirect::factory()->count(15)->create();
        $this->enterRequest($space);

        $redirects = Redirect::query()->get();
        $redirects->each(fn (Redirect $redirect) => $redirect->created_at?->toIso8601String());

        $this->assertCount(15, $redirects);
        $this->assertSame([$connection->id], $this->factory->made);
    }

    #[Test]
    public function it_resolves_again_for_a_freshly_loaded_connection(): void
    {
        [$space, $connection] = $this->spaceWithConnection();

        $this->enterRequest($space);
        Redirect::query()->count();

        // The next request loads the space, and so its credentials, anew.
        $this->enterRequest($space->fresh());
        Redirect::query()->count();

        $this->assertSame([$connection->id, $connection->id], $this->factory->made);
        $this->assertSame([], $this->factory->released);
    }

    #[Test]
    public function it_releases_the_previous_connection_when_switching_spaces(): void
    {
        [$first, $firstConnection] = $this->spaceWithConnection();
        [$second] = $this->spaceWithConnection();

        $this->enterRequest($first);
        Redirect::query()->count();
        $this->enterRequest($second);
        Redirect::query()->count();

        $this->assertSame([$firstConnection->id], $this->factory->released);
    }

    /**
     * @return array{Space, SpaceConnection}
     */
    private function spaceWithConnection(): array
    {
        $space = Space::factory()->create();
        $connection = SpaceConnection::factory()->sqlite()->create([
            'space_id' => $space->id,
            'is_default' => true,
        ]);

        return [$space, $connection];
    }

    private function enterRequest(Space $space): void
    {
        $this->app['env'] = 'production';
        $this->app->instance('currentSpace', $space);
    }
}
