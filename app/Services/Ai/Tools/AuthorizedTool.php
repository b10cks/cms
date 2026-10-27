<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;
use Closure;

/** Runs a tool only when the acting user passes the given check. */
final readonly class AuthorizedTool implements AiToolInterface
{
    /**
     * @param  Closure(): bool  $allowed
     */
    public function __construct(
        private AiToolInterface $tool,
        private Closure $allowed,
    ) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function inputSchema(): array
    {
        return $this->tool->inputSchema();
    }

    public function execute(array $input): mixed
    {
        if (! ($this->allowed)()) {
            throw new ToolError('You do not have access to this information.');
        }

        return $this->tool->execute($input);
    }
}
