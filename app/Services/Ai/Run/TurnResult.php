<?php

namespace App\Services\Ai\Run;

/** The outcome of one provider call: the assistant text, every tool call of the turn and usage. */
final readonly class TurnResult
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public TurnUsage $usage,
    ) {}
}
