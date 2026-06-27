<?php

namespace App\Http\Controllers;

use App\Http\Resources\Management\SubscriptionPeriodResource;
use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Management\SubscriptionPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SpaceUsageHistoryController extends Controller
{
    /**
     * The space's billing-period history (newest first), each with its usage rollup.
     */
    public function index(Space $space): ResourceCollection
    {
        $this->authorize('viewBilling', $space);

        $periods = $space->subscriptionPeriods()
            ->orderByDesc('started_at')
            ->get();

        return SubscriptionPeriodResource::collection($periods);
    }

    /**
     * Daily traffic/requests trend within a closed (or open) period, reconstructed
     * from the hourly rollup tables for the page charts.
     */
    public function timeseries(Request $request, Space $space, SubscriptionPeriod $period): JsonResponse
    {
        $this->authorize('viewBilling', $space);

        abort_unless($period->space_id === $space->id, 404);

        $metric = $request->query('metric', 'traffic');
        abort_unless(\in_array($metric, ['traffic', 'requests'], true), 422);

        $start = $period->started_at ?? $period->created_at;
        $end = $period->ended_at ?? now();

        $series = $metric === 'traffic'
            ? $this->dailyTraffic($space, $start, $end)
            : $this->dailyRequests($space, $start, $end);

        return response()->json([
            'data' => [
                'metric' => $metric,
                'start' => $start?->toIso8601String(),
                'end' => $end->toIso8601String(),
                'points' => $series,
            ],
        ]);
    }

    /**
     * @return array<int, array{date: string, value: int}>
     */
    private function dailyTraffic(Space $space, ?Carbon $start, Carbon $end): array
    {
        $rows = SpaceTrafficUsageHourly::where('space_id', $space->id)
            ->whereBetween('hour_timestamp', [$start, $end])
            ->get(['hour_timestamp', 'total_bytes']);

        return $this->groupByDay($rows, 'total_bytes');
    }

    /**
     * @return array<int, array{date: string, value: int}>
     */
    private function dailyRequests(Space $space, ?Carbon $start, Carbon $end): array
    {
        $rows = SpaceApiHitHourly::where('space_id', $space->id)
            ->whereBetween('hour_timestamp', [$start, $end])
            ->get(['hour_timestamp', 'hit_count']);

        return $this->groupByDay($rows, 'hit_count');
    }

    /**
     * Sum an hourly column into per-day buckets. Grouped in PHP to stay driver
     * agnostic; a period spans at most a year of hourly rows.
     *
     * @param  Collection<int, Model>  $rows
     * @return array<int, array{date: string, value: int}>
     */
    private function groupByDay($rows, string $column): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $day = Carbon::parse($row->hour_timestamp)->toDateString();
            $buckets[$day] = ($buckets[$day] ?? 0) + (int) $row->{$column};
        }

        ksort($buckets);

        return array_map(
            fn (string $date, int $value) => ['date' => $date, 'value' => $value],
            array_keys($buckets),
            array_values($buckets),
        );
    }
}
