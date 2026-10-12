<?php

namespace App\Support;

use Closure;

/**
 * Lifts the request time limit for one piece of work.
 *
 * Use this instead of `set_time_limit()`. Under FrankenPHP workers,
 * `set_time_limit(0)` can leave the 59 second timer running: workers keep a 0
 * from an earlier request while still arming `max_input_time`, and PHP only
 * disarms a timer when the current limit is non-zero. A positive limit always
 * re-arms it. Workers also keep runtime ini changes, so the limit is put back
 * afterwards or the worker's next requests would inherit it.
 */
final class ExecutionTime
{
    /**
     * @template T
     *
     * @param  positive-int  $seconds
     * @param  Closure(): T  $work
     * @return T
     */
    public static function extend(int $seconds, Closure $work): mixed
    {
        set_time_limit($seconds);

        try {
            return $work();
        } finally {
            ini_restore('max_execution_time');
        }
    }
}
