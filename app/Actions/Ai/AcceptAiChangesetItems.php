<?php

namespace App\Actions\Ai;

use App\Actions\Content\CreateContent;
use App\Enums\AiChangesetItemStatus;
use App\Events\Space\ContentUpdated;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\DataEntry;
use App\Models\User;
use App\Services\Ai\Assets\AssetChangesetApplier;
use App\Services\Ai\Assets\AssetReview;
use App\Services\Ai\Changesets\BlockTarget;
use App\Services\Ai\Changesets\CanonicalJson;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\ContentTarget;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Ai\Tools\ProposalTool;
use App\Services\Ai\Translation\TranslationLineage;
use App\Services\Auth\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Applies open changeset items per field, entry or changeset, and is the one
 * way an agent's change reaches a target: at once for content, called by
 * {@see ProposalTool::writeContent()}, and on a person's
 * accept for everything else and for conflicts. Items are grouped per entry and
 * checked against its current state: a field still equal to the value the run
 * read applies, any other becomes a conflict (three-way: before, current, after).
 * Applying goes through the target's normal write path and creates a draft;
 * nothing is published.
 *
 * Each entry is one transaction: its items are locked and re-read, the entry is
 * written, and the decision recorded, so neither a lost worker nor a competing
 * decision can leave a written entry with an open proposal, or write it twice.
 * What follows a commit (recount, broadcasts) cannot undo it. See
 * {@see DecidableItems} for the lock order.
 */
class AcceptAiChangesetItems
{
    /** Entry kinds with a row to lock. Asset kinds lock in {@see AssetChangesetApplier}. */
    private const TARGET_MODELS = [
        AiChangesetItem::TARGET_CONTENT => Content::class,
        AiChangesetItem::TARGET_DATA_ENTRY => DataEntry::class,
        AiChangesetItem::TARGET_BLOCK => Block::class,
    ];

    public function __construct(
        private readonly ChangeTargets $targets,
        private readonly AuthorizationService $auth,
        private readonly CreateContent $createContent,
        private readonly AiThreads $threads,
        private readonly AssetChangesetApplier $assets,
        private readonly TranslationLineage $lineage,
    ) {}

    /**
     * @param  list<string>|null  $itemIds  null accepts every open item
     * @param  bool  $overwrite  apply conflicting items over the current value
     * @param  array<string, string>  $values  reviewer edits of text proposals, item ID => text to apply instead
     * @param  bool  $bulk  a group or "accept all" decision: items that need an individual look are left open, and an entry that fails validation is flagged instead of failing the rest
     * @return array{accepted: int, conflicts: int, failed: int, blocked: int}
     *
     * @throws AuthorizationException when the user may not edit one of the targets
     * @throws ValidationException when an entry fails validation outside a bulk decision
     */
    public function handle(AiChangeset $changeset, Space $space, User $user, ?array $itemIds = null, bool $overwrite = false, array $values = [], bool $bulk = false): array
    {
        $items = DecidableItems::query($changeset, $itemIds)->get();
        $bulk = $bulk || $itemIds === null;
        if ($bulk) {
            $items = $items->reject(fn (AiChangesetItem $item) => DecidableItems::needsIndividualLook($item))->values();
            $overwrite = false;
        }
        DecidableItems::authorize($items, $space, $user, $this->targets, $this->auth);

        $assetItems = $items->filter(fn (AiChangesetItem $item) => AssetReview::handles($item->target_type));
        $items = $items->reject(fn (AiChangesetItem $item) => AssetReview::handles($item->target_type));
        $result = ['accepted' => 0, 'conflicts' => 0, 'failed' => 0, 'blocked' => 0];

        try {
            if ($assetItems->isNotEmpty()) {
                $assets = $this->assets->accept($assetItems, $space, $user, $overwrite, $values, $bulk);
                $result = [...$result, ...$assets];
            }

            foreach ($items->groupBy(fn (AiChangesetItem $item) => "{$item->target_type}:".($item->target_id ?? $item->id)) as $group) {
                $decided = $this->decide($group->pluck('id')->all(), $space, $user, "AI: {$changeset->title}", $overwrite, $values, $bulk);
                $result['accepted'] += $decided['accepted'];
                $result['conflicts'] += $decided['conflicts'];
                $result['failed'] += $decided['failed'];
            }
        } finally {
            // Entries decided before a later one threw stay decided and count.
            DecidableItems::settle($changeset->id);
            rescue(fn () => $this->threads->changedByRun($changeset->run_id));
        }

        return $result;
    }

    /**
     * The items of one entry, as one transaction.
     *
     * @param  list<string>  $ids
     * @param  array<string, string>  $values
     * @return array{accepted: int, conflicts: int, failed: int}
     */
    private function decide(array $ids, Space $space, User $user, string $message, bool $overwrite, array $values, bool $bulk): array
    {
        try {
            // Muted, as the entry's save would announce a state not yet committed.
            $decided = Content::withoutBroadcasts(fn () => DecidableItems::transaction(function () use ($ids, $space, $user, $message, $overwrite, $values, $bulk) {
                $items = DecidableItems::lockOpen($ids);
                if ($bulk) {
                    $items = $items->reject(fn (AiChangesetItem $item) => DecidableItems::needsIndividualLook($item))->values();
                }

                /** @var AiChangesetItem|null $first */
                $first = $items->first();

                [$applied, $conflicted, $content] = match (true) {
                    $first === null => [collect(), collect(), null],
                    $first->target_type === AiChangesetItem::TARGET_SELECTION => [$this->selection($items, $values), collect(), null],
                    $first->operation === AiChangesetItem::OPERATION_CREATE => $this->create($first, $space, $user, $message),
                    default => $this->update($items, $space, $user, $message, $overwrite, $values),
                };

                $applied->each(fn (AiChangesetItem $item) => $item->update([
                    'status' => AiChangesetItemStatus::Accepted,
                    'decided_by_id' => $user->id,
                    'decided_at' => now(),
                ]));

                $this->lineage->recordEdits($applied->filter(fn (AiChangesetItem $item) => $item->target_type === AiChangesetItem::TARGET_CONTENT && $item->final !== null)->values());

                return ['accepted' => $applied->count(), 'conflicts' => $conflicted->count(), 'content' => $content];
            }));
        } catch (ValidationException $e) {
            if (! $bulk) {
                throw $e;
            }

            return ['accepted' => 0, 'conflicts' => 0, 'failed' => $this->flagInvalid($ids, $e)];
        }

        if ($decided['content'] !== null) {
            // To everyone, the requesting tab too: nobody typed this. Sent after the outermost commit, so never for a write that rolled back.
            rescue(fn () => broadcast(new ContentUpdated($decided['content'], $space)));
        }

        return [...$decided, 'failed' => 0];
    }

    /**
     * Flagged, so the next bulk accept leaves it to a person too.
     *
     * @param  list<string>  $ids
     */
    private function flagInvalid(array $ids, ValidationException $e): int
    {
        return DecidableItems::transaction(function () use ($ids, $e) {
            $items = DecidableItems::lockOpen($ids);
            $items->each(fn (AiChangesetItem $item) => $item->update(['flags' => [
                ...($item->flags ?? []),
                ['code' => 'invalid', 'message' => implode(' ', Arr::flatten($e->errors()))],
            ]]));

            return $items->count();
        });
    }

    /**
     * @param  Collection<int, AiChangesetItem>  $items  locked open update items of one entry
     * @param  array<string, string>  $values
     * @return array{0: Collection<int, AiChangesetItem>, 1: Collection<int, AiChangesetItem>, 2: Content|null}
     */
    private function update(Collection $items, Space $space, User $user, string $message, bool $overwrite, array $values): array
    {
        /** @var AiChangesetItem $first */
        $first = $items->first();
        $target = $this->targets->for($first->target_type);
        // Locked before it is read, so an editor's save conflicts by field here
        // and never as a version conflict on the write.
        if ($target instanceof ContentTarget) {
            $current = $target->locked((string) $first->target_id);
        } else {
            (self::TARGET_MODELS[$first->target_type])::query()->whereKey($first->target_id)->lockForUpdate()->value('id');
            $current = $target->find((string) $first->target_id);
        }

        if (! $current) {
            $items->each(fn (AiChangesetItem $item) => $item->update(['status' => AiChangesetItemStatus::Conflict, 'current' => null]));

            return [collect(), $items, null];
        }

        $document = $current->document;
        $applied = collect();
        $conflicted = collect();

        foreach ($items as $item) {
            $now = FieldPath::get($current->document, (string) $item->path);
            $forced = $overwrite && $item->status === AiChangesetItemStatus::Conflict;

            try {
                if (! $forced && ! CanonicalJson::equals($now, $item->before)) {
                    if (! $this->holdsOwnWrite($item, $now)) {
                        throw new ToolError('changed');
                    }
                    $item->before = $now;
                }
                // Only text proposals can be edited; the edit replaces the proposal.
                $edit = \is_string($item->after) && ($values[$item->id] ?? $item->after) !== $item->after ? $values[$item->id] : null;
                $document = FieldPath::set($document, (string) $item->path, $edit ?? $item->after);
                $item->final = $edit;
                $applied->push($item);
            } catch (ToolError) {
                // Edited after the run read it, or its block is gone.
                $item->update(['status' => AiChangesetItemStatus::Conflict, 'current' => $now]);
                $conflicted->push($item);
            }
        }

        if ($applied->isNotEmpty()) {
            try {
                // The schema may have changed since the proposal was checked.
                $target->validate($space, $current, $applied->mapWithKeys(fn (AiChangesetItem $item) => [(string) $item->path => $item->final ?? $item->after])->all());
            } catch (ToolError $e) {
                throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
            }

            $target->apply($space, $current, $document, $user, $message);
        }

        return [$applied, $conflicted, $applied->isNotEmpty() && $current->model instanceof Content ? $current->model : null];
    }

    /** Whether the field holds what this changeset last wrote there: a correction of its own text, not someone's change. */
    private function holdsOwnWrite(AiChangesetItem $item, mixed $now): bool
    {
        $written = AiChangesetItem::query()
            ->where('changeset_id', $item->changeset_id)
            ->where('target_type', $item->target_type)
            ->where('target_id', $item->target_id)
            ->where('path', $item->path)
            ->where('status', AiChangesetItemStatus::Accepted->value)
            ->orderByDesc('id')
            ->first();

        return $written !== null && CanonicalJson::equals($now, $written->final ?? $written->after);
    }

    /**
     * The open editor applies selection proposals; only the reviewer's edit is recorded here.
     *
     * @param  Collection<int, AiChangesetItem>  $items
     * @param  array<string, string>  $values
     * @return Collection<int, AiChangesetItem>
     */
    private function selection(Collection $items, array $values): Collection
    {
        return $items->each(function (AiChangesetItem $item) use ($values) {
            $edit = $values[$item->id] ?? null;
            $item->final = \is_string($item->after) && $edit !== null && $edit !== $item->after ? $edit : null;
        });
    }

    /**
     * The new entry's ID lands on the item in the transaction that creates the
     * entry: a repeat finds the item accepted and creates nothing.
     *
     * @return array{0: Collection<int, AiChangesetItem>, 1: Collection<int, AiChangesetItem>, 2: Content|null}
     */
    private function create(AiChangesetItem $item, Space $space, User $user, string $message): array
    {
        if ($item->target_type === AiChangesetItem::TARGET_BLOCK) {
            $target = $this->targets->for(AiChangesetItem::TARGET_BLOCK);
            \assert($target instanceof BlockTarget);

            try {
                // Another block may have taken the slug since.
                $item->target_id = $target->create($target->validateNew((array) $item->after))->id;
            } catch (ToolError $e) {
                throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
            }

            return [collect([$item]), collect(), null];
        }

        $after = (array) $item->after;
        // Locking read, for the same reason as {@see ContentTarget::locked()}: someone may have created it since the agent read.
        if (isset($after['i18n_parent_id']) && Content::query()->where('i18n_parent_id', $after['i18n_parent_id'])->where('language_iso', $after['language_iso'] ?? null)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['language_iso' => ["Someone created the {$after['language_iso']} translation in the meantime, so nothing was written. Read it again."]]);
        }

        $content = new Content;
        $this->createContent->execute([...$after, 'message' => $message], $content, $space, $user);
        $item->target_id = $content->id;

        return [collect([$item]), collect(), $content];
    }
}
