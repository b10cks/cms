<?php

namespace Tests\Feature;

use App\Services\PostHog\ExceptionReporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ExceptionReportingTest extends TestCase
{
    #[Test]
    public function itReportsUnexpectedExceptionsToPosthog()
    {
        $this->mock(ExceptionReporter::class)
            ->shouldReceive('report')
            ->once()
            ->withArgs(fn (Throwable $e) => $e->getMessage() === 'boom');

        app(ExceptionHandler::class)->report(new RuntimeException('boom'));
    }

    #[Test]
    public function itDoesNotReportExpectedExceptions()
    {
        $this->mock(ExceptionReporter::class)
            ->shouldNotReceive('report');

        app(ExceptionHandler::class)->report(
            ValidationException::withMessages(['name' => 'The name field is required.'])
        );
    }
}
