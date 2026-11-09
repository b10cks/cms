<?php

namespace Tests\Unit\Models\Management;

use App\Models\Management\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaceContentRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    #[Test]
    public function two_bumps_within_one_second_get_different_revisions(): void
    {
        Carbon::setTestNow('2026-11-09 09:48:10.100');
        $space = Space::factory()->create(['content_updated_at' => null]);

        $space->touchContentUpdatedAt();
        $first = $space->rv;

        Carbon::setTestNow('2026-11-09 09:48:10.900');
        $space->touchContentUpdatedAt();

        $this->assertSame($first + 1, $space->rv);
        $this->assertSame($space->rv, $space->fresh()->rv);
    }

    #[Test]
    public function a_stale_instance_still_moves_past_the_stored_revision(): void
    {
        Carbon::setTestNow('2026-11-09 09:48:10');
        $space = Space::factory()->create(['content_updated_at' => '2026-11-09 09:48:12']);
        $stale = Space::query()->find($space->id);

        $space->touchContentUpdatedAt();
        $stale->touchContentUpdatedAt();

        $this->assertSame(Carbon::parse('2026-11-09 09:48:14')->getTimestamp(), $stale->rv);
        $this->assertSame($stale->rv, $space->fresh()->rv);
    }
}
