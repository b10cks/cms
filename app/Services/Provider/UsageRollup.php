<?php

namespace App\Services\Provider;

use App\Enums\SubscriptionStatus;
use App\Models\Management\SubscriptionPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Per top-level team usage for one month: plan revenue, AI spend, and
 * storage, traffic and API requests against the plan quotas of the team's
 * spaces (child teams included).
 *
 * Sources stay in the management database, no space database is opened:
 * revenue and AI spend come from subscription periods (AI spend only once a
 * period closed), storage from the cached live footprint or the last period
 * snapshot, traffic and API requests from the hourly usage tables. API
 * requests have no quota on any plan, so they are a plain count.
 */
class UsageRollup
{
    public const STORAGE_WARNING_SHARE = 0.85;

    private const CACHE_SECONDS = 600;

    public function __construct(private readonly SubscriptionRevenue $revenue) {}

    /** @return array<int, array<string, mixed>> */
    public function forMonth(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();

        return Cache::remember(
            'provider:usage:'.$start->format('Y-m'),
            self::CACHE_SECONDS,
            fn () => $this->compute($start, $start->copy()->endOfMonth()),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function compute(Carbon $start, Carbon $end): array
    {
        $teams = DB::table('teams')->whereNull('deleted_at')->get(['id', 'name', 'parent_id', 'avatar', 'color'])->keyBy('id');
        $spaces = DB::table('spaces')->whereNull('deleted_at')->whereNotNull('team_id')->get(['id', 'team_id']);
        // No space id lists: periods and subscriptions of deleted spaces are
        // simply never looked up, which keeps these single plain queries.
        $periods = SubscriptionPeriod::query()
            ->where('started_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
            ->orderByDesc('started_at')
            ->get()
            ->unique('space_id')
            ->keyBy('space_id');

        $current = $this->revenue->currentBySpace();
        $traffic = $this->sumBySpace('space_traffic_usage_hourly', 'total_bytes', $start, $end);
        $downloads = $this->sumBySpace('space_download_usage_hourly', 'bytes_sent', $start, $end);
        $requests = $this->sumBySpace('space_api_hits_hourly', 'hit_count', $start, $end);
        // The storage snapshot of the most recent periods, for spaces without a cached live value.
        $latestStorage = SubscriptionPeriod::query()
            ->whereNotNull('storage_bytes')
            ->whereBetween('started_at', [$start->copy()->subMonths(2), $end])
            ->orderByDesc('started_at')
            ->get(['space_id', 'storage_bytes'])
            ->unique('space_id')
            ->pluck('storage_bytes', 'space_id');

        $rows = [];

        foreach ($spaces as $space) {
            $topId = $this->topLevelTeamId($space->team_id, $teams);
            if ($topId === null) {
                continue;
            }

            $period = $periods->get($space->id);
            $subscription = $current->get($space->id);
            $quotas = $period?->quotas ?? $subscription?->effectiveQuotas() ?? [];
            $storage = (float) (Cache::get("space.usage.storage.{$space->id}") ?? $latestStorage->get($space->id) ?? 0);
            $trafficUsed = (float) ($traffic->get($space->id) ?? 0) + (float) ($downloads->get($space->id) ?? 0);

            $row = $rows[$topId] ??= [
                'revenue' => 0.0,
                'ai_spend' => 0.0,
                'storage' => ['used' => 0.0, 'limit' => 0.0],
                'traffic' => ['used' => 0.0, 'limit' => 0.0],
                'api_requests' => 0,
                'trial_ended' => false,
                'payment_failed' => false,
            ];

            $row['revenue'] += $period ? $this->monthlyPrice($period) : 0.0;
            $row['ai_spend'] += (float) ($period?->ai_spend_usd ?? 0);
            $row['api_requests'] += (int) ($requests->get($space->id) ?? 0);
            $row['storage'] = $this->addMetric($row['storage'], $storage, $quotas['storage'] ?? null);
            $row['traffic'] = $this->addMetric($row['traffic'], $trafficUsed, $quotas['traffic'] ?? null);
            $row['trial_ended'] = $row['trial_ended'] || ($subscription && $this->revenue->trialEnded($subscription));
            $row['payment_failed'] = $row['payment_failed'] || ($subscription && $this->revenue->paymentFailed($subscription));

            $rows[$topId] = $row;
        }

        return collect($rows)
            ->map(function (array $row, string $teamId) use ($teams) {
                $team = $teams->get($teamId);
                $storageShare = $this->share($row['storage']);

                return [
                    'team' => [
                        'id' => $team->id,
                        'name' => $team->name,
                        'color' => $team->color,
                    ],
                    'revenue' => round($row['revenue'], 2),
                    'ai_spend' => round($row['ai_spend'], 2),
                    'storage' => ['used' => $row['storage']['used'], 'share' => $storageShare],
                    'traffic' => ['used' => $row['traffic']['used'], 'share' => $this->share($row['traffic'])],
                    'api_requests' => $row['api_requests'],
                    'flags' => array_values(array_filter([
                        $storageShare !== null && $storageShare > self::STORAGE_WARNING_SHARE ? 'storage' : null,
                        $row['payment_failed'] ? TenantDirectory::FLAG_PAYMENT_FAILED : null,
                        $row['trial_ended'] ? TenantDirectory::FLAG_TRIAL_ENDED : null,
                    ])),
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /** @return Collection<string, int|float> */
    private function sumBySpace(string $table, string $column, Carbon $start, Carbon $end): Collection
    {
        return DB::table($table)
            ->whereBetween('hour_timestamp', [$start, $end])
            ->select('space_id', DB::raw("sum({$column}) as total"))
            ->groupBy('space_id')
            ->pluck('total', 'space_id');
    }

    /**
     * Unlimited spaces (no quota) count neither towards the usage nor the
     * limit of a share, or one unlimited space would hide the others' limits.
     *
     * @param  array{used: float, limit: float}  $metric
     * @return array{used: float, limit: float}
     */
    private function addMetric(array $metric, float $used, mixed $limit): array
    {
        if ($limit === null || (float) $limit <= 0) {
            return $metric;
        }

        return ['used' => $metric['used'] + $used, 'limit' => $metric['limit'] + (float) $limit];
    }

    /** @param  array{used: float, limit: float}  $metric */
    private function share(array $metric): ?float
    {
        return $metric['limit'] > 0 ? round($metric['used'] / $metric['limit'], 4) : null;
    }

    private function monthlyPrice(SubscriptionPeriod $period): float
    {
        if ($period->status === SubscriptionStatus::OnTrial->value) {
            return 0.0;
        }

        return match ($period->billing_period) {
            'year' => (float) $period->price / 12,
            'month' => (float) $period->price,
            default => 0.0,
        };
    }

    /** @param  Collection<string, object>  $teams */
    private function topLevelTeamId(string $teamId, Collection $teams): ?string
    {
        $current = $teams->get($teamId);
        $guard = 0;

        while ($current?->parent_id !== null && $teams->has($current->parent_id) && $guard++ < 64) {
            $current = $teams->get($current->parent_id);
        }

        return $current?->id;
    }
}
