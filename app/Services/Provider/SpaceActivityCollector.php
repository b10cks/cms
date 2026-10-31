<?php

namespace App\Services\Provider;

use App\Models\Management\Space;
use App\Models\Space\AiRun;
use App\Models\Space\ContentVersion;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rolls a space's content and AI activity up into `space_activity_daily` and
 * `editor_activity_daily`, so the provider dashboard never opens a space DB.
 * Re-running a window replaces the space's rows for those days.
 */
class SpaceActivityCollector
{
    /**
     * Collect the days from `$since` until today. Null rebuilds the space's
     * whole history, which also happens when the space has no rows yet.
     */
    public function collect(Space $space, ?CarbonImmutable $since): void
    {
        $hasRows = DB::table('space_activity_daily')->where('space_id', $space->id)->exists();
        $from = $since !== null && $hasRows ? $since->startOfDay() : null;

        $restore = SpaceContext::enter($space);

        try {
            [$days, $editors] = $this->read($from);
        } finally {
            $restore();
        }

        DB::transaction(function () use ($space, $from, $days, $editors) {
            DB::table('space_activity_daily')
                ->where('space_id', $space->id)
                ->when($from, fn ($query) => $query->where('day', '>=', $from->toDateString()))
                ->delete();

            foreach (array_chunk($days, 500) as $chunk) {
                DB::table('space_activity_daily')->insert(array_map(
                    fn (array $row) => ['space_id' => $space->id] + $row,
                    $chunk,
                ));
            }

            // Activity never un-happens, so editor days are only ever added.
            foreach (array_chunk($editors, 500) as $chunk) {
                DB::table('editor_activity_daily')->insertOrIgnore($chunk);
            }
        });
    }

    /**
     * @return array{0: list<array{day: string, versions_created: int, entries_published: int, ai_runs: int, ai_cost_usd: float}>, 1: list<array{user_id: string, day: string}>}
     */
    private function read(?CarbonImmutable $from): array
    {
        $days = [];
        $row = static fn (string $day): array => ['day' => $day, 'versions_created' => 0, 'entries_published' => 0, 'ai_runs' => 0, 'ai_cost_usd' => 0.0];

        $grouped = fn (string $column) => ContentVersion::query()->toBase()
            ->selectRaw("DATE({$column}) as day, COUNT(*) as total")
            ->whereNotNull($column)
            ->when($from, fn ($query) => $query->where($column, '>=', $from))
            ->groupByRaw("DATE({$column})")
            ->get();

        foreach ($grouped('created_at') as $item) {
            $days[$item->day] ??= $row($item->day);
            $days[$item->day]['versions_created'] = (int) $item->total;
        }

        foreach ($grouped('published_at') as $item) {
            $days[$item->day] ??= $row($item->day);
            $days[$item->day]['entries_published'] = (int) $item->total;
        }

        $runs = new AiRun;
        if (Schema::connection($runs->getConnectionName())->hasTable($runs->getTable())) {
            $spend = AiRun::query()->toBase()
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total, SUM(cost_usd) as cost')
                ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
                ->groupByRaw('DATE(created_at)')
                ->get();

            foreach ($spend as $item) {
                $days[$item->day] ??= $row($item->day);
                $days[$item->day]['ai_runs'] = (int) $item->total;
                $days[$item->day]['ai_cost_usd'] = round((float) $item->cost, 6);
            }
        }

        $editors = [];
        foreach (['created_by_id' => 'created_at', 'published_by_id' => 'published_at'] as $user => $column) {
            ContentVersion::query()->toBase()
                ->selectRaw("DISTINCT {$user} as user_id, DATE({$column}) as day")
                ->whereNotNull($user)
                ->whereNotNull($column)
                ->when($from, fn ($query) => $query->where($column, '>=', $from))
                ->get()
                ->each(function ($item) use (&$editors) {
                    $editors["{$item->day}|{$item->user_id}"] = ['user_id' => $item->user_id, 'day' => $item->day];
                });
        }

        return [array_values($days), array_values($editors)];
    }
}
