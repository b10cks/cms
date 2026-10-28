<?php

namespace App\Actions\Ai;

use App\Actions\Content\CreateContent;
use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Changesets\BlockTarget;
use App\Services\Ai\Changesets\CanonicalJson;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Learning\EditLearning;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Auth\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Accepts open changeset items per field, entry or changeset. Items are
 * grouped per entry and checked against its current state: a field still
 * equal to the value the run read applies, any other becomes a conflict
 * (three-way: before, current, after). Applying goes through the target's
 * normal write path and creates a draft; nothing is published.
 */
class AcceptAiChangesetItems
{
    public function __construct(
        private readonly ChangeTargets $targets,
        private readonly AuthorizationService $auth,
        private readonly CreateContent $createContent,
        private readonly EditLearning $learning,
        private readonly AiThreads $threads,
    ) {}

    /**
     * @param  list<string>|null  $itemIds  null accepts every open item
     * @param  bool  $overwrite  apply conflicting items over the current value
     * @param  array<string, string>  $values  reviewer edits of text proposals, item ID => text to apply instead
     * @return array{accepted: int, conflicts: int}
     *
     * @throws AuthorizationException when the user may not edit one of the targets
     */
    public function handle(AiChangeset $changeset, Space $space, User $user, ?array $itemIds = null, bool $overwrite = false, array $values = []): array
    {
        $items = DecidableItems::query($changeset, $itemIds)->get();
        DecidableItems::authorize($items, $space, $user, $this->targets, $this->auth);

        $accepted = 0;
        $conflicts = 0;
        $edited = collect();
        $message = "AI: {$changeset->title}";

        foreach ($items->groupBy(fn (AiChangesetItem $item) => "{$item->target_type}:".($item->target_id ?? $item->id)) as $group) {
            /** @var AiChangesetItem $first */
            $first = $group->first();

            [$applied, $conflicted] = match (true) {
                $first->target_type === AiChangesetItem::TARGET_SELECTION => [$this->selection($group, $values), collect()],
                $first->operation === AiChangesetItem::OPERATION_CREATE => $this->create($first, $space, $user),
                default => $this->update($group, $space, $user, $message, $overwrite, $values),
            };

            $applied->each(fn (AiChangesetItem $item) => $item->update([
                'status' => AiChangesetItemStatus::Accepted,
                'decided_by_id' => $user->id,
                'decided_at' => now(),
            ]));
            $accepted += $applied->count();
            $conflicts += $conflicted->count();
            $edited = $edited->merge($applied->filter(fn (AiChangesetItem $item) => $item->final !== null));
        }

        if ($edited->isNotEmpty()) {
            $this->learning->fromAcceptedEdits($space, $user, $edited);
        }

        $changeset->refreshCounts();
        $this->threads->changedByRun($changeset->run_id);

        return ['accepted' => $accepted, 'conflicts' => $conflicts];
    }

    /**
     * @param  Collection<int, AiChangesetItem>  $items  open update items of one entry
     * @param  array<string, string>  $values
     * @return array{0: Collection<int, AiChangesetItem>, 1: Collection<int, AiChangesetItem>}
     */
    private function update(Collection $items, Space $space, User $user, string $message, bool $overwrite, array $values): array
    {
        /** @var AiChangesetItem $first */
        $first = $items->first();
        $target = $this->targets->for($first->target_type);
        $current = $target->find((string) $first->target_id);

        if (! $current) {
            $items->each(fn (AiChangesetItem $item) => $item->update(['status' => AiChangesetItemStatus::Conflict, 'current' => null]));

            return [collect(), $items];
        }

        $document = $current->document;
        $applied = collect();
        $conflicted = collect();

        foreach ($items as $item) {
            $now = FieldPath::get($current->document, (string) $item->path);
            $forced = $overwrite && $item->status === AiChangesetItemStatus::Conflict;

            try {
                if (! $forced && ! CanonicalJson::equals($now, $item->before)) {
                    throw new ToolError('changed');
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

        return [$applied, $conflicted];
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
     * @return array{0: Collection<int, AiChangesetItem>, 1: Collection<int, AiChangesetItem>}
     */
    private function create(AiChangesetItem $item, Space $space, User $user): array
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

            return [collect([$item]), collect()];
        }

        $content = new Content;
        $this->createContent->execute((array) $item->after, $content, $space, $user);
        $item->target_id = $content->id;

        return [collect([$item]), collect()];
    }
}
