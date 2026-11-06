<?php

namespace App\Actions\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Auth\AuthorizationService;

/**
 * Rejects open changeset items; nothing is written to the targets. Items an
 * accept decided first stay decided, and an accept that comes later finds them
 * closed: both lock the same item rows, see {@see DecidableItems}.
 */
class RejectAiChangesetItems
{
    /** Items locked per transaction, so a large reject never blocks the rest of an operation for long. */
    private const CHUNK = 200;

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

        $rejected = 0;

        try {
            foreach (array_chunk($items->pluck('id')->all(), self::CHUNK) as $ids) {
                $rejected += DecidableItems::transaction(fn () => AiChangesetItem::query()
                    ->whereKey(DecidableItems::lockOpen($ids)->pluck('id')->all())
                    ->update([
                        'status' => AiChangesetItemStatus::Rejected->value,
                        'decided_by_id' => $user->id,
                        'decided_at' => now(),
                    ]));
            }
        } finally {
            DecidableItems::settle($changeset->id);
            rescue(fn () => $this->threads->changedByRun($changeset->run_id));
        }

        return $rejected;
    }
}
