<?php

namespace App\Services\Ai\Run;

use App\Models\Space\AiRun;
use App\Services\Ai\Exceptions\ProviderFailure;
use App\Services\Ai\Exceptions\ProviderFailureKind;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A background run's durable retry state after transient provider failures,
 * kept in `setup.provider_retry` so it survives any worker. While a retry is
 * pending, the transcript stays at its last checkpoint: a failed call adds no
 * message, so nothing can claim that a request or its images reached the model.
 *
 * - `attempts`: retries since the last answered call, bounded by `max_attempts`.
 * - `not_before`: no provider call before this time, whoever advances the run.
 * - `total`: retries over the whole run.
 * - `waited_seconds`: backoff that does not count against `max_seconds`.
 * - `unconfirmed_calls`: interrupted calls whose full cost nobody reported. Never
 *   reset, and bounded by `max_unconfirmed_calls`, since each can bill unseen.
 * - `failure`: kind, status and code of the failure being retried.
 */
final class ProviderRetry
{
    /** Record what failed and what that call may have cost. */
    public static function note(AiRun $run, ProviderFailure $failure): void
    {
        $state = self::state($run);
        $state['failure'] = $failure->toArray();
        $state['not_before'] = null;
        if ($failure->kind->possiblyBilled()) {
            $state['unconfirmed_calls']++;
        }

        $run->setup = [...$run->setup, 'provider_retry' => $state];
    }

    /** Set the next attempt. Null when the failure is permanent or its retries are used up. */
    public static function schedule(AiRun $run, ProviderFailure $failure): ?CarbonInterface
    {
        $state = self::state($run);
        $limits = config('ai.runs.provider_retries');

        if (! $failure->kind->isTransient()
            || $state['attempts'] >= (int) $limits['max_attempts']
            || $state['unconfirmed_calls'] > (int) $limits['max_unconfirmed_calls']) {
            return null;
        }

        $backoff = (int) $limits['backoff_seconds'] * 2 ** $state['attempts'];
        $seconds = (int) min(max($backoff, $failure->retryAfterSeconds ?? 0), (int) $limits['max_backoff_seconds']);
        $at = now()->addSeconds($seconds);

        $run->setup = [...$run->setup, 'provider_retry' => [
            ...$state,
            'attempts' => $state['attempts'] + 1,
            'total' => $state['total'] + 1,
            'waited_seconds' => $state['waited_seconds'] + $seconds,
            'not_before' => $at->toIso8601String(),
        ]];

        return $at;
    }

    /** A provider call was answered: the next failure starts a new series. The run's totals stay. */
    public static function answered(AiRun $run): void
    {
        if (! isset($run->setup['provider_retry'])) {
            return;
        }

        $run->setup = [...$run->setup, 'provider_retry' => [...self::state($run), 'attempts' => 0, 'not_before' => null, 'failure' => null]];
    }

    /** A retry is owed, due or not. */
    public static function isPending(AiRun $run): bool
    {
        return isset($run->setup['provider_retry']['not_before']);
    }

    /** A retry is owed and its time has not come. */
    public static function isWaiting(AiRun $run): bool
    {
        return self::isPending($run) && Carbon::parse($run->setup['provider_retry']['not_before'])->isFuture();
    }

    public static function attempts(AiRun $run): int
    {
        return self::state($run)['attempts'];
    }

    public static function waitedSeconds(AiRun $run): int
    {
        return self::state($run)['waited_seconds'];
    }

    /** The last failure is one that says nothing about the entries of the run's batch. */
    public static function sparesEntries(AiRun $run): bool
    {
        $kind = ProviderFailureKind::tryFrom((string) (self::state($run)['failure']['kind'] ?? ''));

        return $kind?->sparesEntries() ?? false;
    }

    /** @return array{attempts: int, total: int, unconfirmed_calls: int, waited_seconds: int, not_before: string|null, failure: array<string, mixed>|null} */
    public static function state(AiRun $run): array
    {
        return ($run->setup['provider_retry'] ?? []) + [
            'attempts' => 0,
            'total' => 0,
            'unconfirmed_calls' => 0,
            'waited_seconds' => 0,
            'not_before' => null,
            'failure' => null,
        ];
    }
}
