<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\SpaceStatsController;
use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Space\Asset;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\DataSource;
use App\Models\Space\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(SpaceStatsController::class)]
class SpaceStatsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function space_stats_include_new_counts_for_the_selected_timeframe(): void
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $viewer = User::factory()->create();
        $oldUser = User::factory()->create();
        $newUser = User::factory()->create();

        $this->assignSpaceRole($space, $viewer, 'admin');
        $this->assignSpaceRole($space, $oldUser, 'editor');
        $this->assignSpaceRole($space, $newUser, 'editor');

        DB::table('space_user')
            ->where('space_id', $space->id)
            ->where('user_id', $viewer->id)
            ->update([
                'created_at' => '2026-04-01 09:00:00',
                'updated_at' => '2026-04-01 09:00:00',
            ]);

        DB::table('space_user')
            ->where('space_id', $space->id)
            ->where('user_id', $oldUser->id)
            ->update([
                'created_at' => '2026-04-05 09:00:00',
                'updated_at' => '2026-04-05 09:00:00',
            ]);

        DB::table('space_user')
            ->where('space_id', $space->id)
            ->where('user_id', $newUser->id)
            ->update([
                'created_at' => '2026-04-15 09:00:00',
                'updated_at' => '2026-04-15 09:00:00',
            ]);

        $oldBlock = Block::factory()->create(['created_at' => '2026-04-02 10:00:00']);
        $newBlock = Block::factory()->create(['created_at' => '2026-04-16 10:00:00']);

        // slug and full_slug must agree, or the Content saving hook records a
        // slug-change redirect and skews the redirect counts asserted below.
        Content::factory()->create([
            'block_id' => $oldBlock->id,
            'slug' => 'old-content',
            'full_slug' => '/old-content',
            'created_at' => '2026-04-03 10:00:00',
        ]);
        Content::factory()->create([
            'block_id' => $newBlock->id,
            'slug' => 'new-content',
            'full_slug' => '/new-content',
            'created_at' => '2026-04-17 10:00:00',
        ]);

        Asset::factory()->create([
            'size' => 1_024,
            'created_at' => '2026-04-04 10:00:00',
        ]);
        Asset::factory()->create([
            'size' => 2_048,
            'created_at' => '2026-04-18 10:00:00',
        ]);

        DataSource::factory()->create(['created_at' => '2026-04-05 10:00:00']);
        DataSource::factory()->create(['created_at' => '2026-04-19 10:00:00']);

        Redirect::factory()->create(['created_at' => '2026-04-06 10:00:00']);
        Redirect::factory()->create(['created_at' => '2026-04-20 10:00:00']);

        $this->actingAs($viewer);

        $response = $this->getJson("/mgmt/v1/spaces/{$space->id}/stats?".http_build_query([
            'start_date' => Carbon::parse('2026-04-10 00:00:00')->toIso8601String(),
            'end_date' => Carbon::parse('2026-04-20 23:59:59')->toIso8601String(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('content.count.total', 2);
        $response->assertJsonPath('content.count.new', 1);
        $response->assertJsonPath('content.count.blocks', 2);
        $response->assertJsonPath('content.count.new_blocks', 1);
        $response->assertJsonPath('assets.storage.total_size', 3072);
        $response->assertJsonPath('assets.storage.new_size', 2048);
        $response->assertJsonPath('data_sources.data_sources.count.total', 2);
        $response->assertJsonPath('data_sources.data_sources.count.new', 1);
        $response->assertJsonPath('redirects.count.total', 2);
        $response->assertJsonPath('redirects.count.new', 1);
        $response->assertJsonPath('user_activity.total_users', 3);
        $response->assertJsonPath('user_activity.new_users', 1);
    }

    #[Test]
    public function weekly_trends_fold_days_into_iso_weeks_across_the_year_boundary(): void
    {
        Carbon::setTestNow('2027-01-06 12:00:00');

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $viewer = User::factory()->create();
        $this->assignSpaceRole($space, $viewer, 'admin');

        $block = Block::factory()->create();

        // 2026-12-28 .. 2027-01-03 is ISO week 2026-W53; 2027-01-04 opens 2027-W01.
        foreach (['2026-12-28 09:00:00', '2026-12-31 23:00:00', '2027-01-01 01:00:00', '2027-01-04 10:00:00'] as $i => $createdAt) {
            Content::factory()->create([
                'block_id' => $block->id,
                'slug' => "entry-$i",
                'full_slug' => "/entry-$i",
                'created_at' => $createdAt,
            ]);
        }

        $this->actingAs($viewer);

        $response = $this->getJson("/mgmt/v1/spaces/{$space->id}/stats?".http_build_query([
            'period' => 'weekly',
            'start_date' => '2026-12-30',
            'end_date' => '2027-01-05',
        ]));

        $response->assertOk();
        $response->assertJsonPath('trends.periods', ['2026-12-28', '2027-01-04']);
        $response->assertJsonPath('trends.content_creation', ['2026-12-28' => 3, '2027-01-04' => 1]);
    }

    #[Test]
    public function trends_weight_response_time_by_hits_and_count_new_blocks_and_data_sources(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $viewer = User::factory()->create();
        $this->assignSpaceRole($space, $viewer, 'admin');

        // Week of 2026-04-06: 10 hits at 100 ms and 30 hits at 200 ms average 175 ms, not 150.
        foreach (['2026-04-07 10:00:00' => [10, 1_000], '2026-04-08 10:00:00' => [30, 6_000]] as $hour => [$hits, $timeTaken]) {
            DB::table((new SpaceApiHitHourly)->getTable())->insert([
                'id' => (string) Str::ulid(),
                'space_id' => $space->id,
                'hour_timestamp' => $hour,
                'hit_count' => $hits,
                'time_taken_sum' => $timeTaken,
            ]);
        }

        Block::factory()->create(['created_at' => '2026-04-07 09:00:00']);
        Block::factory()->create(['created_at' => '2026-04-14 09:00:00']);
        DataSource::factory()->create(['created_at' => '2026-04-14 09:00:00']);

        $this->actingAs($viewer);

        $response = $this->getJson("/mgmt/v1/spaces/{$space->id}/stats?".http_build_query([
            'period' => 'weekly',
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-15',
        ]));

        $response->assertOk();
        $response->assertJsonPath('trends.periods', ['2026-04-06', '2026-04-13']);
        $response->assertJsonPath('trends.api_usage', ['2026-04-06' => 40, '2026-04-13' => 0]);
        $response->assertJsonPath('trends.response_time', ['2026-04-06' => 175, '2026-04-13' => null]);
        $response->assertJsonPath('trends.block_creation', ['2026-04-06' => 1, '2026-04-13' => 1]);
        $response->assertJsonPath('trends.data_source_creation', ['2026-04-06' => 0, '2026-04-13' => 1]);
    }
}
