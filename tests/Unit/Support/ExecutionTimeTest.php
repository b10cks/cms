<?php

namespace Tests\Unit\Support;

use App\Support\ExecutionTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExecutionTimeTest extends TestCase
{
    #[Test]
    public function it_lifts_the_limit_for_the_work_and_restores_it_even_when_the_work_throws(): void
    {
        $original = ini_get('max_execution_time');

        $this->assertSame(42, ExecutionTime::extend(1800, static fn (): int => (int) ini_get('max_execution_time') === 1800 ? 42 : 0));
        $this->assertSame($original, ini_get('max_execution_time'));

        try {
            ExecutionTime::extend(1800, static fn () => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
        }

        $this->assertSame($original, ini_get('max_execution_time'), 'Workers keep runtime ini changes, so a failure must not leak the long limit.');
    }
}
