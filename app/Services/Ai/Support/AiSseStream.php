<?php

namespace App\Services\Ai\Support;

use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Dto\StreamEventType;
use App\Services\Ai\Exceptions\AiServiceException;
use Closure;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared Server-Sent-Events transport for the AI streaming endpoints.
 *
 * Centralises the boilerplate that used to be copy-pasted across every stream
 * controller: output-buffer handling, keep-alive pings, client-disconnect
 * detection (so we stop consuming — and being billed for — an upstream stream
 * once the browser goes away) and uniform error handling that logs the real
 * exception server-side while only surfacing a generic, reference-tagged
 * message to the client.
 */
class AiSseStream
{
    public const PING_INTERVAL = 15;

    /**
     * @param  Closure(): Generator<StreamEvent>  $events  Factory that yields the AI stream events.
     * @param  array<string, mixed>  $logContext  Extra context attached to error logs.
     */
    public static function response(Closure $events, array $logContext = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($events, $logContext) {
            @ignore_user_abort(true);
            @set_time_limit(0);

            if (ob_get_level() === 0) {
                ob_start();
            }

            $write = static function (string $payload): void {
                echo $payload;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            $write(": ping\n\n");
            $lastActivity = time();

            try {
                foreach ($events() as $event) {
                    if (connection_aborted()) {
                        break;
                    }

                    if ((time() - $lastActivity) >= self::PING_INTERVAL) {
                        $write(": ping\n\n");
                    }

                    $write($event->toJsonLine()."\n\n");
                    $lastActivity = time();

                    if (connection_aborted()) {
                        break;
                    }

                    if ($event->type === StreamEventType::Done || $event->type === StreamEventType::Error) {
                        break;
                    }
                }
            } catch (AiServiceException $e) {
                // Intentional, user-facing failure — surface its safe message
                // and machine reason directly, no error reference needed.
                if (! connection_aborted()) {
                    $write(StreamEvent::error($e->getMessage(), $e->reason)->toJsonLine()."\n\n");
                }
            } catch (\Throwable $e) {
                $ref = (string) Str::uuid();

                Log::error('AI stream failed', array_merge($logContext, [
                    'ref' => $ref,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]));

                if (! connection_aborted()) {
                    $write(StreamEvent::error("Something went wrong while generating. Reference: {$ref}")->toJsonLine()."\n\n");
                }
            }

            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'close',
        ]);
    }
}
