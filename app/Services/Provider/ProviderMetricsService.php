<?php

namespace App\Services\Provider;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceDownloadUsageHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Management\Team;
use App\Models\User;
use App\Support\EditionGate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Monthly instance metrics for the provider dashboard. Months are buckets in
 * the app timezone; the last one is the current, partial month.
 *
 * Every figure is a {@see self::metric()}: the series over the range plus the
 * current value and the values to compare it with. Flows (sign-ups, publishes,
 * requests, spend) compare month-to-date with the same days of the previous
 * month and of the month a year ago, so the first days of a month don't read
 * as a collapse. Stocks (MRR, paying spaces, rates) compare with the value at
 * the end of those months.
 *
 * Revenue and AI are omitted when billing is off (self-hosted).
 */
class ProviderMetricsService
{
    /** Range key => months, null = year to date. */
    public const RANGES = ['6m' => 6, '12m' => 12, '24m' => 24, 'ytd' => null];

    private const CACHE_TTL = 900;

    private const ACTIVATION_DAYS = 14;

    private const COHORT_MONTHS = 12;

    private CarbonImmutable $now;

    private CarbonImmutable $current;

    /** @var list<CarbonImmutable> months shown, oldest first */
    private array $range;

    /** @var list<CarbonImmutable> every month a figure or a comparison needs, oldest first */
    private array $window;

    private ?CarbonImmutable $instanceStart;

    public function cached(string $range): array
    {
        $billing = EditionGate::billingEnabled() ? 'billing' : 'free';

        return Cache::remember("provider.metrics.v1.{$range}.{$billing}", self::CACHE_TTL, fn () => $this->build($range));
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $range): array
    {
        $this->now = CarbonImmutable::now();
        $this->current = $this->now->startOfMonth();
        $count = self::RANGES[$range] ?? $this->now->month;
        $this->range = $this->months($this->current->subMonths($count - 1), $this->current);
        $this->window = $this->months(min($this->range[0], $this->current->subMonths(13)), $this->current);

        $firstUser = User::withTrashed()->min('created_at');
        $this->instanceStart = $firstUser ? CarbonImmutable::parse($firstUser)->startOfMonth() : null;

        $payload = [
            'months' => array_map($this->key(...), $this->range),
            'as_of' => $this->now->toIso8601String(),
        ];

        if (EditionGate::billingEnabled()) {
            $snapshots = $this->snapshots();
            $payload['revenue'] = $this->revenue($snapshots);
        }

        $payload['growth'] = $this->growth();
        $payload['engagement'] = $this->engagement();

        if (isset($snapshots)) {
            $payload['ai'] = $this->ai($snapshots);
        }

        return $payload;
    }

    // Revenue

    /**
     * MRR per space and account at the end of every month (now for the
     * current one), from the paid billing periods. A yearly period counts a
     * twelfth of its price; a trial counts from the day it ends.
     *
     * @return array{months: list<CarbonImmutable>, space: array<string, array<string, float>>, account: array<string, array<string, float>>, ai: array<string, float>, plan: array<string, string>, names: array<string, string>}
     */
    private function snapshots(): array
    {
        $periods = DB::table('subscription_periods as p')
            ->join('subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->whereNotNull('s.lemon_squeezy_id')
            ->where('p.price', '>', 0)
            ->get(['p.space_id', 'p.plan_id', 'p.plan_name', 'p.price', 'p.billing_period', 'p.quotas', 'p.started_at', 'p.ended_at', 's.trial_ends_at'])
            ->map(function (object $period): array {
                $start = CarbonImmutable::parse($period->started_at);
                $trialEnd = $period->trial_ends_at ? CarbonImmutable::parse($period->trial_ends_at) : null;
                $quotas = json_decode((string) $period->quotas, true);

                return [
                    'space' => $period->space_id,
                    'plan' => $period->plan_id ?? $period->plan_name,
                    'name' => $period->plan_name,
                    'mrr' => match ($period->billing_period) {
                        'year' => (float) $period->price / 12,
                        'forever' => 0.0,
                        default => (float) $period->price,
                    },
                    'ai' => (float) (is_array($quotas) ? ($quotas['aiCredit'] ?? 0) : 0),
                    'from' => ($trialEnd !== null && $trialEnd->gt($start) ? $trialEnd : $start)->getTimestamp(),
                    'to' => $period->ended_at ? CarbonImmutable::parse($period->ended_at)->getTimestamp() : PHP_INT_MAX,
                ];
            })
            ->filter(fn (array $period) => $period['mrr'] > 0 && $period['from'] < $period['to'])
            ->values();

        $earliest = $periods->min('from');
        $first = $this->window[0];
        if ($earliest !== null) {
            $first = min($first, CarbonImmutable::createFromTimestamp($earliest, $this->now->timezone)->startOfMonth());
        }
        $months = $this->months($first, $this->current);
        $accounts = $this->accounts($periods->pluck('space')->unique()->all());

        $space = [];
        $account = [];
        $ai = [];
        $plan = [];
        $names = [];
        foreach ($months as $month) {
            $key = $this->key($month);
            $at = min($month->addMonth(), $this->now)->getTimestamp();
            $space[$key] = [];
            $account[$key] = [];
            $ai[$key] = 0.0;

            foreach ($periods as $period) {
                if ($period['from'] > $at || $period['to'] <= $at) {
                    continue;
                }
                $space[$key][$period['space']] = ($space[$key][$period['space']] ?? 0) + $period['mrr'];
                $owner = $accounts[$period['space']];
                $account[$key][$owner] = ($account[$key][$owner] ?? 0) + $period['mrr'];
                $ai[$key] += $period['ai'];

                if ($month->equalTo($this->current)) {
                    $plan[$period['space']] = $period['plan'];
                    $names[$period['plan']] = $period['name'];
                }
            }
        }

        return compact('months', 'space', 'account', 'ai', 'plan', 'names');
    }

    /**
     * Who pays: the top-level team of the space's team, so an agency with
     * client sub-teams is one account. A space without a team is its own.
     *
     * @param  list<string>  $spaceIds
     * @return array<string, string> space id => account id
     */
    private function accounts(array $spaceIds): array
    {
        $parents = Team::withTrashed()->pluck('parent_id', 'id')->all();
        $root = function (?string $team) use ($parents): ?string {
            for ($hops = 0; $team !== null && ($parents[$team] ?? null) !== null && $hops < 64; $hops++) {
                $team = $parents[$team];
            }

            return $team;
        };

        $teams = Space::withTrashed()->whereIn('id', $spaceIds)->pluck('team_id', 'id')->all();

        return collect($spaceIds)
            ->mapWithKeys(fn (string $space) => [$space => $root($teams[$space] ?? null) ?? "space:{$space}"])
            ->all();
    }

    private function revenue(array $snapshots): array
    {
        $mrr = [];
        $payingSpaces = [];
        $payingTeams = [];
        $arpa = [];
        $movement = [];
        $revenueChurn = [];
        $logoChurn = [];

        $previous = [];
        foreach ($snapshots['months'] as $month) {
            $key = $this->key($month);
            $now = $snapshots['account'][$key];
            $before = $previous;
            $previous = $now;
            $total = array_sum($now);
            $paying = count($now);

            $mrr[$key] = $total;
            $payingSpaces[$key] = count($snapshots['space'][$key]);
            $payingTeams[$key] = $paying;
            $arpa[$key] = $paying > 0 ? $total / $paying : null;

            $step = ['new' => 0.0, 'expansion' => 0.0, 'churn' => 0.0];
            $lost = 0;
            foreach ($now + $before as $owner => $_) {
                $from = $before[$owner] ?? 0.0;
                $to = $now[$owner] ?? 0.0;
                match (true) {
                    $from == 0.0 => $step['new'] += $to,
                    $to > $from => $step['expansion'] += $to - $from,
                    default => $step['churn'] += $to - $from,
                };
                if ($from > 0 && $to == 0.0) {
                    $lost++;
                }
            }
            $movement[$key] = $step;
            $base = array_sum($before);
            $revenueChurn[$key] = $base > 0 ? -$step['churn'] / $base : null;
            $logoChurn[$key] = count($before) > 0 ? $lost / count($before) : null;
        }

        $netNew = array_map(fn (array $step) => array_sum($step), $movement);
        $current = $this->key($this->current);

        return [
            'mrr' => $this->stock($mrr),
            'arr' => $mrr[$current] * 12,
            'net_new_mrr' => $this->stock($netNew),
            'movement' => collect(['new', 'expansion', 'churn'])->mapWithKeys(fn (string $part) => [
                $part => array_map(fn (CarbonImmutable $month) => round($movement[$this->key($month)][$part], 2), $this->range),
            ])->all(),
            'paying_spaces' => $this->stock($payingSpaces),
            'paying_teams' => $this->stock($payingTeams),
            'arpa' => $this->stock($arpa),
            'revenue_churn' => $this->stock($revenueChurn),
            'logo_churn' => $this->stock($logoChurn),
            'by_plan' => $this->byPlan($snapshots),
            'retention' => $this->retention($snapshots),
        ];
    }

    /**
     * @return list<array{name: string, mrr: float}>
     */
    private function byPlan(array $snapshots): array
    {
        $space = $snapshots['space'][$this->key($this->current)];
        $plans = Plan::query()->whereIn('id', array_keys($snapshots['names']))->get()->keyBy('id');

        $totals = [];
        foreach ($snapshots['plan'] as $spaceId => $plan) {
            $totals[$plan] = ($totals[$plan] ?? 0) + $space[$spaceId];
        }
        arsort($totals);

        return array_map(fn (string $plan, float $mrr) => [
            'name' => $plans->get($plan)?->getTranslatedName() ?? $snapshots['names'][$plan],
            'mrr' => round($mrr, 2),
        ], array_keys($totals), array_values($totals));
    }

    /**
     * Revenue retention by start month: each cohort is the accounts whose
     * first paying month it was, and each cell is the cohort's MRR that many
     * months later relative to its first month (1.0 = 100 %).
     *
     * @return list<array{month: string, mrr: float, values: list<float>}>
     */
    private function retention(array $snapshots): array
    {
        $keys = array_map($this->key(...), $snapshots['months']);
        $start = [];
        foreach ($keys as $key) {
            foreach ($snapshots['account'][$key] as $owner => $_) {
                $start[$owner] ??= $key;
            }
        }

        $cohorts = array_slice(array_map($this->key(...), $this->range), -self::COHORT_MONTHS);

        return array_map(function (string $cohort) use ($snapshots, $keys, $start) {
            $members = array_keys($start, $cohort, true);
            $offset = array_search($cohort, $keys, true);
            $base = array_sum(array_map(fn ($owner) => $snapshots['account'][$cohort][$owner], $members));
            $values = [];

            for ($k = 0; $base > 0 && $k < self::COHORT_MONTHS && isset($keys[$offset + $k]); $k++) {
                $month = $snapshots['account'][$keys[$offset + $k]];
                $values[] = round(array_sum(array_map(fn ($owner) => $month[$owner] ?? 0, $members)) / $base, 4);
            }

            return ['month' => $cohort, 'mrr' => round($base, 2), 'values' => $values];
        }, $cohorts);
    }

    // Growth

    private function growth(): array
    {
        $from = $this->window[0];

        $spaces = Space::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $this->now->subDays(self::ACTIVATION_DAYS))
            ->get(['id', 'created_at']);
        $firstPublish = DB::table('space_activity_daily')
            ->whereIn('space_id', $spaces->modelKeys())
            ->where('entries_published', '>', 0)
            ->groupBy('space_id')
            ->selectRaw('space_id, MIN(day) as day')
            ->pluck('day', 'space_id');

        $activation = [];
        foreach ($spaces->groupBy(fn (Space $space) => $space->created_at->format('Y-m')) as $month => $created) {
            $activated = $created->filter(function (Space $space) use ($firstPublish) {
                $day = $firstPublish[$space->id] ?? null;

                return $day !== null
                    && substr((string) $day, 0, 10) <= $space->created_at->copy()->addDays(self::ACTIVATION_DAYS)->toDateString();
            });
            $activation[$month] = $activated->count() / $created->count();
        }

        return [
            'new_users' => $this->flow($this->daily(User::query()->toBase(), 'created_at')),
            'new_spaces' => $this->flow($this->daily(Space::query()->toBase(), 'created_at')),
            'new_teams' => $this->flow($this->daily(Team::query()->toBase(), 'created_at')),
            'activation' => $this->latestStock(array_map(
                fn (CarbonImmutable $month) => $activation[$this->key($month)] ?? null,
                array_combine(array_map($this->key(...), $this->window), $this->window),
            )),
        ];
    }

    // Engagement

    private function engagement(): array
    {
        $editors = [];
        foreach ($this->window as $month) {
            $end = min($month->endOfMonth(), $this->now)->startOfDay();
            $editors[$this->key($month)] = DB::table('editor_activity_daily')
                ->whereBetween('day', [$end->subDays(6)->toDateString(), $end->toDateString()])
                ->distinct()
                ->count('user_id');
        }

        $traffic = $this->daily(SpaceTrafficUsageHourly::query()->toBase(), 'hour_timestamp', 'SUM(total_bytes)');
        foreach ($this->daily(SpaceDownloadUsageHourly::query()->toBase(), 'hour_timestamp', 'SUM(bytes_sent)') as $day => $bytes) {
            $traffic[$day] = ($traffic[$day] ?? 0) + $bytes;
        }

        return [
            'weekly_active_editors' => $this->stock($editors),
            'entries_published' => $this->flow($this->daily(DB::table('space_activity_daily'), 'day', 'SUM(entries_published)')),
            'api_requests' => $this->flow($this->daily(SpaceApiHitHourly::query()->toBase(), 'hour_timestamp', 'SUM(hit_count)')),
            'traffic_bytes' => $this->flow($traffic),
        ];
    }

    // AI

    /**
     * AI revenue is the AI credit (`quotas.aiCredit`, USD a month) that paid
     * plans include; spend is the cost of every AI run in every space, free
     * ones included. Both are USD. The current month's margin weighs spend so
     * far against the share of the month that has passed.
     */
    private function ai(array $snapshots): array
    {
        $spendDaily = $this->daily(DB::table('space_activity_daily'), 'day', 'SUM(ai_cost_usd)');
        $spend = $this->flow($spendDaily);
        $spendByMonth = [];
        foreach ($spendDaily as $day => $cost) {
            $spendByMonth[substr($day, 0, 7)] = ($spendByMonth[substr($day, 0, 7)] ?? 0) + $cost;
        }

        $elapsed = ($this->now->getTimestamp() - $this->current->getTimestamp())
            / ($this->current->addMonth()->getTimestamp() - $this->current->getTimestamp());

        $revenue = [];
        $margin = [];
        $using = [];
        $usingCount = [];
        foreach ($this->window as $month) {
            $key = $this->key($month);
            $revenue[$key] = $snapshots['ai'][$key] ?? 0.0;
            $earned = $revenue[$key] * ($month->equalTo($this->current) ? $elapsed : 1);
            $margin[$key] = $earned > 0 ? ($earned - ($spendByMonth[$key] ?? 0)) / $earned : null;

            $paying = array_keys($snapshots['space'][$key] ?? []);
            $active = $paying === [] ? 0 : DB::table('space_activity_daily')
                ->whereIn('space_id', $paying)
                ->whereBetween('day', [$month->toDateString(), $month->endOfMonth()->toDateString()])
                ->where('ai_runs', '>', 0)
                ->distinct()
                ->count('space_id');
            $usingCount[$key] = $active;
            $using[$key] = $paying === [] ? null : $active / count($paying);
        }

        return [
            'revenue_usd' => $this->stock($revenue),
            'spend_usd' => $spend,
            'margin' => $this->stock($margin),
            'spaces_using_ai' => $this->stock($using),
            'spaces_using_ai_count' => $this->stock($usingCount),
        ];
    }

    // Shapes

    /**
     * A figure: `series` over the range, `current`, and what to compare it
     * with. `previous` and `year_ago` are null where the instance has no data.
     *
     * @return array{series: list<float|null>, current: float|null, previous: float|null, year_ago: float|null}
     */
    private function metric(array $series, ?float $current, ?float $previous, ?float $yearAgo, CarbonImmutable $at): array
    {
        $round = fn (?float $value) => $value === null ? null : round($value, 4);

        return [
            'series' => array_map($round, $series),
            'current' => $round($current),
            'previous' => $this->existed($at->subMonth()) ? $round($previous) : null,
            'year_ago' => $this->existed($at->subYear()) ? $round($yearAgo) : null,
        ];
    }

    /** @param  array<string, float|int|null>  $byMonth */
    private function stock(array $byMonth): array
    {
        $value = fn (CarbonImmutable $month) => isset($byMonth[$this->key($month)]) ? (float) $byMonth[$this->key($month)] : null;

        return $this->metric(
            array_map($value, $this->range),
            $value($this->current),
            $value($this->current->subMonth()),
            $value($this->current->subYear()),
            $this->current,
        );
    }

    /**
     * A stock whose latest months can be empty (activation needs 14 days):
     * `current` is the latest month with a value.
     *
     * @param  array<string, float|null>  $byMonth
     */
    private function latestStock(array $byMonth): array
    {
        $at = collect($this->window)->reverse()->first(fn (CarbonImmutable $month) => $byMonth[$this->key($month)] !== null);
        $value = fn (CarbonImmutable $month) => $byMonth[$this->key($month)] ?? null;

        return $this->metric(
            array_map($value, $this->range),
            $at ? $value($at) : null,
            $at ? $value($at->subMonth()) : null,
            $at ? $value($at->subYear()) : null,
            $at ?? $this->current,
        );
    }

    /**
     * A flow from daily values: monthly sums, with the comparisons taken over
     * the same days of month as have passed in the current one.
     *
     * @param  array<string, float>  $daily  Y-m-d => value
     */
    private function flow(array $daily): array
    {
        $months = [];
        foreach ($daily as $day => $value) {
            $months[substr($day, 0, 7)] = ($months[substr($day, 0, 7)] ?? 0) + $value;
        }

        $toDate = function (CarbonImmutable $month) use ($daily): float {
            $prefix = $this->key($month);
            $sum = 0.0;
            foreach ($daily as $day => $value) {
                if (str_starts_with($day, $prefix) && (int) substr($day, 8, 2) <= $this->now->day) {
                    $sum += $value;
                }
            }

            return $sum;
        };

        return $this->metric(
            array_map(fn (CarbonImmutable $month) => (float) ($months[$this->key($month)] ?? 0), $this->range),
            (float) ($months[$this->key($this->current)] ?? 0),
            $toDate($this->current->subMonth()),
            $toDate($this->current->subYear()),
            $this->current,
        );
    }

    /**
     * Sum (or count) per day of `$column` across the window.
     *
     * @return array<string, float> Y-m-d => value
     */
    private function daily(Builder $query, string $column, string $aggregate = 'COUNT(*)'): array
    {
        return $query
            ->selectRaw("DATE({$column}) as bucket, {$aggregate} as value")
            ->where($column, '>=', $this->window[0]->toDateString())
            ->groupByRaw("DATE({$column})")
            ->pluck('value', 'bucket')
            ->mapWithKeys(fn ($value, $day) => [substr((string) $day, 0, 10) => (float) $value])
            ->all();
    }

    private function existed(CarbonImmutable $month): bool
    {
        return $this->instanceStart !== null && $month->startOfMonth()->gte($this->instanceStart);
    }

    /** @return list<CarbonImmutable> */
    private function months(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $months;
    }

    private function key(CarbonImmutable $month): string
    {
        return $month->format('Y-m');
    }
}
