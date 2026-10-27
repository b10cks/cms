<?php

namespace App\Services\Ai\Run;

/** A piece of assistant text streamed by a driver during a turn. */
final readonly class TextDelta
{
    public function __construct(public string $text) {}
}
