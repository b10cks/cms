<?php

namespace App\Actions\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Auth\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Open items of a changeset and the edit rights deciding on them needs. */
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
