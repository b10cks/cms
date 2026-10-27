<?php

namespace App\Actions\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Events\Ai\AiInboxChanged;
use App\Models\Management\AiChangeset;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Auth\AuthorizationService;

/** Rejects open changeset items; nothing is written to the targets. */
class RejectAiChangesetItems
{
    public function __construct(
        private readonly ChangeTargets $targets,
        private readonly AuthorizationService $auth,
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
        if (! $changeset->inline) {
            broadcast(new AiInboxChanged($space->id, changesetId: $changeset->id));
        }

        return $rejected;
    }
}
