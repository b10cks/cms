<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CollectSpaceActivityCommand;
use App\Models\Management\Space;
use App\Services\Provider\SpaceActivityCollector;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(CollectSpaceActivityCommand::class)]
#[CoversClass(SpaceActivityCollector::class)]
class CollectSpaceActivityCommandTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function it_rolls_edits_publishes_and_ai_runs_up_per_day(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $space = Space::factory()->create();
        $this->setUpSeparateSpaceDatabase($space);
        $db = DB::connection('space_test');

        $version = fn (string $createdAt, string $author, ?string $publishedAt = null, ?string $publisher = null) => [
            'id' => (string) Str::ulid(), 'content_id' => (string) Str::ulid(),
            'created_at' => $createdAt, 'created_by_id' => $author,
            'published_at' => $publishedAt, 'published_by_id' => $publisher,
        ];
        $db->table('content_versions')->insert([
            $version('2026-10-01 09:00:00', 'ann'),
            $version('2026-10-01 17:00:00', 'ann', '2026-10-02 08:00:00', 'bob'),
        ]);
        $db->table('ai_runs')->insert([
            'id' => (string) Str::ulid(), 'space_id' => $space->id, 'actor_id' => (string) Str::ulid(),
            'surface' => 'thread', 'setup' => '{}', 'transcript' => '[]', 'cost_usd' => 0.25,
            'created_at' => '2026-10-02 10:00:00', 'updated_at' => '2026-10-02 10:00:00',
        ]);

        $this->artisan('provider:collect-activity')->assertSuccessful();

        $days = DB::table('space_activity_daily')->where('space_id', $space->id)->orderBy('day')->get()->keyBy('day');
        $this->assertSame(2, (int) $days['2026-10-01']->versions_created);
        $this->assertSame(1, (int) $days['2026-10-02']->entries_published);
        $this->assertSame(1, (int) $days['2026-10-02']->ai_runs);
        $this->assertEqualsWithDelta(0.25, (float) $days['2026-10-02']->ai_cost_usd, 0.0001);
        $this->assertSame(
            ['2026-10-01 ann', '2026-10-02 bob'],
            DB::table('editor_activity_daily')->orderBy('day')->get()->map(fn ($row) => "{$row->day} {$row->user_id}")->all(),
        );

        Carbon::setTestNow();
    }
}
