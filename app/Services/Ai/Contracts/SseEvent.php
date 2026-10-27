<?php

namespace App\Services\Ai\Contracts;

/** Anything {@see \App\Services\Ai\Support\AiSseStream} can write. */
interface SseEvent
{
    public function toJsonLine(): string;

    /** The stream closes after a terminal event. */
    public function isTerminal(): bool;
}
