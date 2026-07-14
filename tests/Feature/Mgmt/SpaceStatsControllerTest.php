<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\SpaceStatsController;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\DataSource;
use App\Models\Space\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $this->registerSqliteDateFormat();

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

    /**
     * The stats service groups by MySQL's DATE_FORMAT(), which SQLite lacks.
     * Register it as a user-defined function so the queries run in tests.
     */
    private function registerSqliteDateFormat(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'DATE_FORMAT',
            static function (?string $value, string $format): ?string {
                if ($value === null) {
                    return null;
                }

                return date(
                    strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%u' => 'W', '%H' => 'H', '%i' => 'i', '%s' => 's']),
                    strtotime($value),
                );
            },
            2,
        );
    }
}
