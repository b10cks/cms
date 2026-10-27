<?php

namespace App\Services\Ai\Contracts;

/** A tool whose last result cites knowledge sources, surfaced as `source` events. */
interface ProvidesSources
{
    /**
     * @return list<array<string, mixed>>
     */
    public function takeSources(): array;
}
