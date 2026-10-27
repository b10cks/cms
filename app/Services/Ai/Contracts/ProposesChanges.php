<?php

namespace App\Services\Ai\Contracts;

/** A tool whose last result added changeset items, surfaced as `proposal` events. */
interface ProposesChanges
{
    /**
     * @return list<array{changeset_id: string, item_id: string, target_type: string, target_id: string|null, path: string|null}>
     */
    public function takeProposals(): array;
}
