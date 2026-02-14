<?php

namespace App\Services\Ai\Contracts;

interface AiToolInterface
{
    public function name(): string;

    public function description(): string;

    public function inputSchema(): array;

    public function execute(array $input): mixed;
}
