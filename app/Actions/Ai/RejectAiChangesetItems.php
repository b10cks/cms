<?php

namespace App\Actions\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Auth\AuthorizationService;

/** Rejects open changeset items; nothing is written to the targets. */
class RejectAiChangesetItems
{
    public function __construct(
        private readonly ChangeTargets $targets,
        private readonly AuthorizationService $auth,
        private readonly AiThreads $threads,
    ) {}

    /**
     * @param  list<string>|null  $itemIds  null rejects every open item
     */
    public function handle(AiChangeset $changeset, Space $space, User $user, ?array $itemIds = null): int
    {
        $items = DecidableItems::query($changeset, $itemIds)->get();
        DecidableItems::authorize($items, $space, $user, $this->targets, $this->auth);

        $rejected = DecidableItems::query($changeset, $items->modelKeys())->update([
            'status' => AiChangesetItemStatus::Rejected->value,
            'decided_by_id' => $user->id,
            'decided_at' => now(),
        ]);

        $changeset->refreshCounts();
        $this->threads->changedByRun($changeset->run_id);

        return $rejected;
    }
}
