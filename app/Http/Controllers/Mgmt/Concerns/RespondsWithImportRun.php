<?php

namespace App\Http\Controllers\Mgmt\Concerns;

use App\DTOs\ImportExport\ImportResult;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Support\AiSseStream;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use App\Services\ImportExport\ImportRun;
use Closure;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an import as server-sent events for clients sending
 * `Accept: text/event-stream`, and as one JSON response otherwise. Only the
 * stream outlives the request time limit, so large files need it.
 */
trait RespondsWithImportRun
{
    /**
     * @param  string  $subject  What is imported, for messages: "asset data", "redirects".
     * @param  Closure(): Generator<int, array{processed: int, total: int}, mixed, ImportResult>  $start
     * @param  array<string, mixed>  $logContext
     */
    protected function importResponse(Request $request, string $subject, Closure $start, array $logContext = []): JsonResponse|StreamedResponse
    {
        $logContext['file'] = $request->file('file')?->getClientOriginalName();

        try {
            $run = $start();

            if ($this->wantsImportStream($request)) {
                return $this->streamImport($run, $subject, $logContext);
            }

            return response()->json(ImportRun::drain($run)->toArray());
        } catch (ImportValidationException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $this->reportImportFailure($e, $subject, $logContext)], 500);
        }
    }

    protected function wantsImportStream(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'text/event-stream');
    }

    /**
     * @param  Generator<int, array{processed: int, total: int}, mixed, ImportResult>  $run
     * @param  array<string, mixed>  $logContext
     */
    protected function streamImport(Generator $run, string $subject, array $logContext = []): StreamedResponse
    {
        return AiSseStream::response(
            fn (): Generator => $this->importEvents($run, $subject, $logContext),
            ['endpoint' => 'import', ...$logContext],
            stopOnDisconnect: false,
        );
    }

    /**
     * @param  Generator<int, array{processed: int, total: int}, mixed, ImportResult>  $run
     * @param  array<string, mixed>  $logContext
     * @return Generator<StreamEvent>
     */
    private function importEvents(Generator $run, string $subject, array $logContext): Generator
    {
        try {
            foreach ($run as $progress) {
                yield StreamEvent::status(json_encode($progress));
            }
        } catch (ImportValidationException|InvalidArgumentException|ValidationException $e) {
            yield StreamEvent::error($e->getMessage());

            return;
        } catch (\Throwable $e) {
            yield StreamEvent::error($this->reportImportFailure($e, $subject, $logContext));

            return;
        }

        yield StreamEvent::done('', $run->getReturn()->toArray());
    }

    /** @param  array<string, mixed>  $logContext */
    private function reportImportFailure(\Throwable $e, string $subject, array $logContext): string
    {
        Log::error(ucfirst($subject).' import failed', [
            ...$logContext,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return "Failed to import {$subject}: {$e->getMessage()}";
    }
}
