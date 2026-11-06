<?php

namespace App\Actions\Ai;

use App\Actions\Content\BasePublishAction;
use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\User;
use App\Services\Ai\Assets\AssetReview;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Auth\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Open items of a changeset, the edit rights deciding on them needs, and the
 * transaction a decision runs in.
 *
 * A decision writes its target and records itself in one transaction per
 * target. Every path takes its locks in the same order: items by primary key
 * in ID order, then the one target row. Nothing holds an item lock while
 * taking another target's. Plain reads come after the locks, because under
 * REPEATABLE READ the first plain read fixes what later plain reads see.
 */
final class DecidableItems
{
    /**
     * @param  list<string>|null  $itemIds
     * @return Builder<AiChangesetItem>
     */
    public static function query(AiChangeset $changeset, ?array $itemIds): Builder
    {
        return AiChangesetItem::query()
            ->where('changeset_id', $changeset->id)
            ->whereIn('status', [AiChangesetItemStatus::Pending->value, AiChangesetItemStatus::Conflict->value])
            ->when($itemIds !== null, fn (Builder $query) => $query->whereIn('id', $itemIds))
            ->orderBy('id');
    }

    /**
     * One decision on the space connection. A deadlock retry runs the callback
     * again from the start, so it must re-read everything it decides on.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return (new AiChangesetItem)->getConnection()->transaction($callback(...), BasePublishAction::TRANSACTION_ATTEMPTS);
    }

    /**
     * Lock items and return those still open. Decided items stay locked but are
     * left out. Locks by primary key so that no other item of the changeset waits.
     *
     * @param  list<string>  $ids
     * @return Collection<int, AiChangesetItem>
     */
    public static function lockOpen(array $ids): Collection
    {
        return AiChangesetItem::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (AiChangesetItem $item) => $item->isOpen())
            ->values();
    }

    /** Recount a changeset under its row lock, so parallel decisions cannot store a stale count. */
    public static function settle(string $changesetId): void
    {
        self::transaction(fn () => AiChangeset::query()->whereKey($changesetId)->lockForUpdate()->first()?->refreshCounts());
    }

    /**
     * Whether a bulk accept must leave the item to an individual decision:
     * conflicts, glossary violations of what would go live (a hint leaves it in, and
     * content has a draft), flags (uncertainty, a failed accept) and the asset
     * domain's own rules (new folders and tags, review flags).
     */
    public static function needsIndividualLook(AiChangesetItem $item): bool
    {
        return $item->status === AiChangesetItemStatus::Conflict
            || ($item->target_type !== AiChangesetItem::TARGET_CONTENT && GlossaryChecker::blocking($item->glossary_findings ?? []) !== [])
            || ($item->flags ?? []) !== []
            || (AssetReview::handles($item->target_type) && AssetReview::excludedFromBulk($item));
    }

    /**
     * Deciding needs the same rights as editing the target.
     *
     * @param  Collection<int, AiChangesetItem>  $items
     *
     * @throws AuthorizationException
     */
    public static function authorize(Collection $items, Space $space, User $user, ChangeTargets $targets, AuthorizationService $auth): void
    {
        foreach ($items->pluck('target_type')->unique() as $type) {
            $ability = $type === AiChangesetItem::TARGET_SELECTION ? 'content.manage' : $targets->for($type)->editAbility();

            if (! $auth->canInSpace($user, $space, $ability)) {
                throw new AuthorizationException('You cannot edit these entries.');
            }
        }
    }
}
