<?php

namespace Tests\Feature\Assets;

use App\Models\Management\Space;
use App\Models\Space\AssetShare;
use App\Services\Asset\AssetSharePinger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The pinger's token selection is the cost *and* privacy boundary of the
 * public share pings: only currently accessible shares may be pinged (a dead
 * token in a broadcast would advertise that it once existed), and the query
 * is capped so a burst can never scale with share count.
 */
class AssetSharePingerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create();
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function only_accessible_shares_are_selected(): void
    {
        $active = $this->makeShare();
        $this->makeShare(['revoked_at' => now()->subMinute()]);
        $this->makeShare(['expires_at' => now()->subMinute()]);
        $this->makeShare()->delete();
        $unexpired = $this->makeShare(['expires_at' => now()->addDay()]);

        $tokens = AssetSharePinger::accessibleTokens();

        $this->assertEqualsCanonicalizing([$active->token, $unexpired->token], $tokens);
    }

    #[Test]
    public function collection_scoping_selects_only_that_collections_shares(): void
    {
        $inCollection = $this->makeShare([
            'source_type' => 'collection',
            'collection_id' => 'col-1',
        ]);
        $this->makeShare([
            'source_type' => 'collection',
            'collection_id' => 'col-2',
        ]);
        $this->makeShare();

        $this->assertSame(
            [$inCollection->token],
            AssetSharePinger::accessibleTokens('col-1'),
        );
    }

    private function makeShare(array $attributes = []): AssetShare
    {
        return AssetShare::create([
            'token' => AssetShare::generateToken(),
            'name' => 'Press kit',
            'source_type' => 'selection',
            'asset_ids' => [],
            ...$attributes,
        ]);
    }
}
