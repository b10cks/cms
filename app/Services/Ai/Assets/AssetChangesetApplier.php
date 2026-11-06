<?php

namespace App\Services\Ai\Assets;

use App\Actions\Ai\AcceptanceReview;
use App\Actions\Ai\DecidableItems;
use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\AssetTag;
use App\Models\User;
use App\Services\Ai\Changesets\CanonicalJson;
use App\Services\Auth\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Accepts asset-domain changeset items: metadata, tags and moves of assets,
 * and new folders and tags. Per asset, everything accepted is checked first
 * and saved once:
 *
 * - a field or folder edited since the run read it conflicts; tags are set
 *   operations on the current tags, so tags an editor changed meanwhile stay
 * - items of a bundle (a proposal that moves the asset) apply together or
 *   not at all
 * - a proposed folder or tag an item needs is created only when that
 *   proposal is accepted with it or was approved with the run's plan;
 *   otherwise the item waits (`dependency_pending`), and a rejected one
 *   turns it into a conflict
 * - metadata is validated against the folder the asset ends up in
 *
 * Accepting a new folder also creates the proposed folders it sits in. A name
 * that exists by now is reused instead of duplicated. Nothing is undone as a
 * group: corrections go through the asset's normal editing and history.
 *
 * Each asset, or new folder or tag accepted on its own, is one transaction
 * over everything its decision reads or changes, locked up front in the order
 * {@see DecidableItems} sets: the items, their bundle, the proposals they
 * refer to, then the asset. Everything is re-read under those locks.
 */
class AssetChangesetApplier
{
    /** @var array<string, true> */
    private array $selected = [];

    /** @var array<string, AiRun|null> */
    private array $runs = [];

    /** @var array<string, true> */
    private array $changesets = [];

    private int $accepted = 0;

    private int $conflicts = 0;

    private int $blocked = 0;

    private bool $bulk = false;

    /** @var array<string, list<array{finding_id: string, reason: string}>> */
    private array $overrides = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $overrideRecords = [];

    public function __construct(
        private readonly AuthorizationService $auth,
        private readonly AssetFieldRules $rules,
        private readonly AssetStructure $structure,
        private readonly AcceptanceReview $review,
    ) {}

    /**
     * @param  Collection<int, AiChangesetItem>  $items  open asset-domain items the reviewer accepts
     * @param  bool  $overwrite  apply conflicting field and folder items over the current value
     * @param  array<string, string>  $values  reviewer edits of text proposals, item ID => text
     * @param  bool  $bulk  leave an item that came to need an individual look since it was selected
     * @param  array<string, list<array{finding_id: string, reason: string}>>  $overrides  reasons for keeping deterministic findings; bulk decisions take none
     * @return array{accepted: int, conflicts: int, blocked: int, edited: Collection<int, AiChangesetItem>}
     *
     * @throws AuthorizationException when the user may not create a folder or tag that is needed
     */
    public function accept(Collection $items, Space $space, User $user, bool $overwrite = false, array $values = [], bool $bulk = false, array $overrides = []): array
    {
        $this->selected = $items->pluck('id')->flip()->map(fn () => true)->all();
        $this->runs = [];
        $this->changesets = [];
        $this->accepted = $this->conflicts = $this->blocked = 0;
        $this->bulk = $bulk;
        $this->overrides = $overrides;
        $this->authorizeCreation($items, $space, $user);
        $edited = collect();

        try {
            foreach ($items->whereIn('target_type', AssetReview::STRUCTURE_TYPES) as $item) {
                $this->structureItem($item, $space, $user);
            }

            foreach ($items->where('target_type', AiChangesetItem::TARGET_ASSET)->groupBy('target_id') as $assetId => $group) {
                $edited = $edited->merge($this->asset((string) $assetId, $group->values(), $space, $user, $overwrite, $values));
            }
        } finally {
            foreach (array_keys($this->changesets) as $id) {
                DecidableItems::settle($id);
            }
        }

        return ['accepted' => $this->accepted, 'conflicts' => $this->conflicts, 'blocked' => $this->blocked, 'edited' => $edited];
    }

    /** A folder or tag proposal the reviewer accepted on its own. */
    private function structureItem(AiChangesetItem $item, Space $space, User $user): void
    {
        $ids = $this->lockSet(collect([$item]));

        $this->decide(function () use ($item, $ids, $space, $user): void {
            $proposal = $this->checked($this->eligible(DecidableItems::lockOpen($ids), [$item->id]), [], $user)->first();
            if ($proposal === null) {
                return;
            }

            $resolved = $this->create($proposal, $space, $user, true);
            if (isset($resolved['problem'])) {
                $this->problem($proposal, $resolved['problem'], $resolved['message'], conflict: $resolved['problem'] !== AssetReview::PROBLEM_DEPENDENCY_PENDING);
            }
        });
    }

    /**
     * @param  Collection<int, AiChangesetItem>  $selected
     * @param  array<string, string>  $values
     * @return Collection<int, AiChangesetItem> applied items the reviewer edited
     */
    private function asset(string $assetId, Collection $selected, Space $space, User $user, bool $overwrite, array $values): Collection
    {
        $ids = $this->lockSet($selected);
        $selectedIds = $selected->pluck('id')->all();

        return $this->decide(function () use ($assetId, $ids, $selectedIds, $space, $user, $overwrite, $values): Collection {
            $items = $this->checked($this->eligible(DecidableItems::lockOpen($ids), $selectedIds), $values, $user);

            return $items->isEmpty() ? collect() : $this->decideAsset($assetId, $items, $space, $user, $overwrite, $values);
        });
    }

    /**
     * The asset's items under lock: conflicts and waits are recorded, the
     * ready ones written to the asset and marked accepted in one go.
     *
     * @param  Collection<int, AiChangesetItem>  $items
     * @param  array<string, string>  $values
     * @return Collection<int, AiChangesetItem> applied items the reviewer edited
     */
    private function decideAsset(string $assetId, Collection $items, Space $space, User $user, bool $overwrite, array $values): Collection
    {
        $asset = Asset::query()->lockForUpdate()->find($assetId);

        if (! $asset) {
            $items->each(fn (AiChangesetItem $item) => $this->conflict($item, null, AssetReview::PROBLEM_CHANGED, 'The asset no longer exists.'));

            return collect();
        }

        $fields = \is_array(data_get($asset->data, 'fields')) ? $asset->data['fields'] : [];
        $ready = collect();

        foreach ($items as $item) {
            $forced = $overwrite && $item->status === AiChangesetItemStatus::Conflict;
            $now = match (true) {
                $item->path === 'folder_id' => $asset->folder_id,
                $item->path === 'tags' => $item->before,
                default => data_get($fields, substr((string) $item->path, \strlen('fields.'))),
            };

            if (! $forced && ! CanonicalJson::equals($now, $item->before)) {
                $this->conflict($item, $now, AssetReview::PROBLEM_CHANGED, 'Edited since the proposal was made.');

                continue;
            }

            $refs = $this->refProblem($item);
            if ($refs !== null) {
                $refs['problem'] === AssetReview::PROBLEM_DEPENDENCY_PENDING
                    ? $this->wait($item, $refs['problem'], $refs['message'])
                    : $this->conflict($item, $now, $refs['problem'], $refs['message']);

                continue;
            }

            $ready->push($item);
        }

        $ready = $this->completeBundles($ready);
        if ($ready->isEmpty()) {
            return collect();
        }

        // Metadata must fit the folder the asset ends up in.
        $move = $ready->firstWhere('path', 'folder_id');
        $destination = $move ? $this->rulesFolder($move->after) : $asset->folder;
        $patches = [];
        foreach ($ready->filter(fn (AiChangesetItem $item) => str_starts_with((string) $item->path, 'fields.')) as $item) {
            [, $language, $field] = explode('.', (string) $item->path, 3);
            $item->final = \is_string($item->after) && isset($values[$item->id]) && $values[$item->id] !== $item->after ? $values[$item->id] : null;
            $patches[$language][$field] = $item->final ?? $item->after;
        }

        $problems = $this->rules->problems($space, $destination, $patches);
        if ($problems !== []) {
            $ready->each(function (AiChangesetItem $item) use ($problems): void {
                $item->final = null;
                $this->conflict($item, $item->before, AssetReview::PROBLEM_INVALID, implode(' ', $problems));
            });

            return collect();
        }

        $tags = array_values(array_filter((array) ($asset->tags ?? []), 'is_string'));
        $added = [];

        foreach ($ready as $item) {
            if ($item->path === 'tags') {
                $add = array_map(fn (string $ref) => $this->resolve($ref, AiChangesetItem::TARGET_ASSET_TAG, $item, $space, $user), (array) ($item->after['add'] ?? []));
                $added = [...$added, ...array_diff($add, $tags)];
                $tags = array_values(array_diff(array_unique([...$tags, ...$add]), (array) ($item->after['remove'] ?? [])));
            }

            if ($item->path === 'folder_id') {
                $asset->folder_id = $item->after === null ? null : $this->resolve((string) $item->after, AiChangesetItem::TARGET_ASSET_FOLDER, $item, $space, $user);
            }
        }

        $asset->tags = $tags;
        $asset->data = [...(\is_array($asset->data) ? $asset->data : []), 'fields' => array_replace_recursive($fields, $patches)];
        $asset->metadata = $this->provenance($asset, $ready->first(), $patches, array_values($added));
        $asset->save();

        $ready->each(fn (AiChangesetItem $item) => $this->accepted($item, $user));

        return $ready->filter(fn (AiChangesetItem $item) => $item->final !== null)->values();
    }

    /**
     * Items of a bundle apply together: when one of its open items is not
     * among those ready, the rest of the bundle waits as well.
     *
     * @param  Collection<int, AiChangesetItem>  $ready
     * @return Collection<int, AiChangesetItem>
     */
    private function completeBundles(Collection $ready): Collection
    {
        if ($ready->every(fn (AiChangesetItem $item) => ! \is_string($item->review['bundle'] ?? null))) {
            return $ready;
        }

        $readyIds = $ready->pluck('id')->flip();
        $open = AiChangesetItem::query()
            ->whereIn('changeset_id', $ready->pluck('changeset_id')->unique()->values())
            ->where('target_type', AiChangesetItem::TARGET_ASSET)
            ->where('target_id', $ready->first()->target_id)
            ->whereIn('status', [AiChangesetItemStatus::Pending->value, AiChangesetItemStatus::Conflict->value])
            ->get();

        return $ready->filter(function (AiChangesetItem $item) use ($readyIds, $open): bool {
            $bundle = $item->review['bundle'] ?? null;
            $missing = \is_string($bundle)
                ? $open->filter(fn (AiChangesetItem $other) => ($other->review['bundle'] ?? null) === $bundle && ! isset($readyIds[$other->id]))
                : collect();

            if ($missing->isEmpty()) {
                return true;
            }

            $this->wait($item, AssetReview::PROBLEM_BUNDLE_INCOMPLETE, 'This change moves the asset and applies together with: '.$missing->pluck('path')->implode(', ').'.');

            return false;
        })->values();
    }

    /**
     * Why a folder or tag the item points at cannot be used now, or null.
     *
     * @return array{problem: string, message: string}|null
     */
    private function refProblem(AiChangesetItem $item): ?array
    {
        foreach (AssetReview::refs($item) as $ref) {
            $itemId = AssetReview::refItemId($ref);

            if ($itemId === null) {
                $exists = $item->path === 'tags' ? $this->structure->tag($ref) : $this->structure->folder($ref);
                if (! $exists) {
                    return ['problem' => AssetReview::PROBLEM_DEPENDENCY_MISSING, 'message' => 'A folder or tag this change uses no longer exists.'];
                }

                continue;
            }

            $state = $this->state(AiChangesetItem::query()->find($itemId), $this->approves($item, $itemId));
            if ($state !== null) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Whether a proposal can be used for a dependent: accepted with its
     * folder or tag still there, or pending and approved. Null when usable.
     *
     * @return array{problem: string, message: string}|null
     */
    private function state(?AiChangesetItem $proposal, bool $approved, int $depth = 0): ?array
    {
        $name = (string) ($proposal?->after['name'] ?? '');

        return match (true) {
            $proposal === null => ['problem' => AssetReview::PROBLEM_DEPENDENCY_MISSING, 'message' => 'A proposed folder or tag this change uses is gone.'],
            $proposal->status === AiChangesetItemStatus::Rejected => ['problem' => AssetReview::PROBLEM_DEPENDENCY_REJECTED, 'message' => "\"{$name}\" was rejected."],
            $proposal->status === AiChangesetItemStatus::Accepted => $this->createdModel($proposal) ? null
                : ['problem' => AssetReview::PROBLEM_DEPENDENCY_MISSING, 'message' => "\"{$name}\" was deleted after it was created."],
            ! $approved => ['problem' => AssetReview::PROBLEM_DEPENDENCY_PENDING, 'message' => "Accept the new \"{$name}\" first."],
            $depth < 10 && ($parent = AssetReview::refItemId($proposal->after['parent'] ?? null)) !== null => $this->state(AiChangesetItem::query()->find($parent), true, $depth + 1),
            default => null,
        };
    }

    /**
     * The ID a reference stands for, creating an approved proposal now.
     * Only called after {@see refProblem()} found the reference usable.
     */
    private function resolve(string $ref, string $type, AiChangesetItem $dependent, Space $space, User $user): string
    {
        $itemId = AssetReview::refItemId($ref);
        if ($itemId === null) {
            return $ref;
        }

        $proposal = AiChangesetItem::query()->findOrFail($itemId);

        return $proposal->status === AiChangesetItemStatus::Accepted
            ? (string) $proposal->target_id
            : (string) $this->create($proposal, $space, $user, $this->approves($dependent, $itemId))['id'];
    }

    /**
     * Create the folder or tag of a proposal, and the proposed folders it
     * sits in, reusing a folder or tag of the same name that exists by now.
     *
     * @return array{id?: string, problem?: string, message?: string}
     *
     * @throws AuthorizationException
     */
    private function create(AiChangesetItem $proposal, Space $space, User $user, bool $approved, int $depth = 0): array
    {
        if ($state = $this->state($proposal, $approved)) {
            return $state;
        }
        if ($proposal->status === AiChangesetItemStatus::Accepted) {
            return ['id' => (string) $proposal->target_id];
        }

        $name = (string) ($proposal->after['name'] ?? '');

        if ($proposal->target_type === AiChangesetItem::TARGET_ASSET_TAG) {
            $this->authorize($space, $user, 'asset_tags.manage');
            $model = $this->structure->tagNamed($name) ?? AssetTag::query()->create(['name' => $name]);
        } else {
            $this->authorize($space, $user, 'asset_folders.manage');
            $parent = $proposal->after['parent'] ?? null;
            $parentItem = AssetReview::refItemId($parent);
            if ($parentItem !== null) {
                // Accepting a folder accepts the proposed folders it sits in.
                $parent = $this->create(AiChangesetItem::query()->findOrFail($parentItem), $space, $user, true, $depth + 1)['id'] ?? null;
            }
            $model = $this->structure->sibling($parent, $name) ?? AssetFolder::query()->create(['name' => $name, 'parent_id' => $parent]);
        }

        $this->structure->forget();
        $proposal->target_id = $model->getKey();
        $this->accepted($proposal, $user);

        return ['id' => (string) $model->getKey()];
    }

    /** Approved: accepted in this request, or approved with the plan of the run that proposed the dependent. */
    private function approves(AiChangesetItem $dependent, string $proposalId): bool
    {
        if (isset($this->selected[$proposalId])) {
            return true;
        }

        return \in_array($proposalId, AssetPlan::fromSetup($this->run($dependent)?->setup ?? [])->approvedStructure, true);
    }

    /** The folder whose field rules apply after a move: a proposed folder inherits those of its nearest existing ancestor. */
    private function rulesFolder(mixed $ref, int $depth = 0): AssetFolder|string|null
    {
        $itemId = AssetReview::refItemId($ref);
        if ($itemId === null || $depth >= 10) {
            return \is_string($ref) ? $ref : null;
        }

        $proposal = AiChangesetItem::query()->find($itemId);

        return $proposal?->status === AiChangesetItemStatus::Accepted
            ? $proposal->target_id
            : $this->rulesFolder($proposal?->after['parent'] ?? null, $depth + 1);
    }

    private function run(AiChangesetItem $item): ?AiRun
    {
        $runId = $item->relationLoaded('changeset') ? $item->changeset?->run_id : AiChangeset::query()->whereKey($item->changeset_id)->value('run_id');

        if ($runId === null) {
            return null;
        }

        return \array_key_exists($runId, $this->runs) ? $this->runs[$runId] : $this->runs[$runId] = AiRun::query()->find($runId);
    }

    private function createdModel(AiChangesetItem $proposal): bool
    {
        return $proposal->target_id !== null && ($proposal->target_type === AiChangesetItem::TARGET_ASSET_TAG
            ? AssetTag::query()->whereKey($proposal->target_id)->exists()
            : AssetFolder::query()->whereKey($proposal->target_id)->exists());
    }

    /**
     * Records which values AI wrote and by what, like automatic classification.
     *
     * @param  array<string, array<string, string>>  $patches
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function provenance(Asset $asset, AiChangesetItem $item, array $patches, array $tags): array
    {
        $metadata = \is_array($asset->metadata) ? $asset->metadata : [];
        $written = [];
        foreach ($patches as $language => $values) {
            foreach (array_keys($values) as $field) {
                $written[] = "{$language}.{$field}";
            }
        }

        $metadata['ai_classification'] = [
            'at' => now()->toIso8601String(),
            'model' => $this->run($item)?->model,
            'fields' => $written,
            'tags' => $tags,
            'changeset_id' => $item->changeset_id,
        ];

        return $metadata;
    }

    private function accepted(AiChangesetItem $item, User $user): void
    {
        AcceptanceReview::record($item, $this->overrideRecords);
        $review = $item->review ?? [];
        unset($review['problem']);

        $item->forceFill([
            'status' => AiChangesetItemStatus::Accepted,
            'decided_by_id' => $user->id,
            'decided_at' => now(),
            'review' => $review ?: null,
        ])->save();
        $this->accepted++;
        $this->changesets[$item->changeset_id] = true;
    }

    private function conflict(AiChangesetItem $item, mixed $current, string $problem, string $message): void
    {
        $this->problem($item, $problem, $message, conflict: true, current: $current);
    }

    private function wait(AiChangesetItem $item, string $problem, string $message): void
    {
        $this->problem($item, $problem, $message, conflict: false);
    }

    private function problem(AiChangesetItem $item, string $problem, string $message, bool $conflict, mixed $current = null): void
    {
        $item->forceFill([
            'review' => [...($item->review ?? []), 'problem' => ['code' => $problem, 'message' => $message]],
            ...($conflict ? ['status' => AiChangesetItemStatus::Conflict, 'current' => $current] : []),
        ])->save();

        $conflict ? $this->conflicts++ : $this->blocked++;
        $this->changesets[$item->changeset_id] = true;
    }

    /**
     * Creating folders or tags needs their manage ability. Checked before
     * anything is written, so a refusal leaves the changeset as it was.
     *
     * @param  Collection<int, AiChangesetItem>  $items
     *
     * @throws AuthorizationException
     */
    private function authorizeCreation(Collection $items, Space $space, User $user): void
    {
        $types = $items->whereIn('target_type', AssetReview::STRUCTURE_TYPES)->pluck('target_type');

        foreach ($items->where('target_type', AiChangesetItem::TARGET_ASSET) as $item) {
            foreach (AssetReview::refs($item) as $ref) {
                if (AssetReview::refItemId($ref) !== null) {
                    $types->push($item->path === 'tags' ? AiChangesetItem::TARGET_ASSET_TAG : AiChangesetItem::TARGET_ASSET_FOLDER);
                }
            }
        }

        foreach ($types->unique() as $type) {
            $this->authorize($space, $user, $type === AiChangesetItem::TARGET_ASSET_TAG ? 'asset_tags.manage' : 'asset_folders.manage');
        }
    }

    /** @throws AuthorizationException */
    private function authorize(Space $space, User $user, string $ability): void
    {
        if (! $this->auth->canInSpace($user, $space, $ability)) {
            throw new AuthorizationException('You cannot create the folders or tags these changes need.');
        }
    }

    /**
     * What an accept may apply now.
     *
     * @param  Collection<int, AiChangesetItem>  $items
     * @param  array<string, string>  $values
     * @return Collection<int, AiChangesetItem>
     */
    private function checked(Collection $items, array $values, User $user): Collection
    {
        $checked = $this->review->check($items, $values, $this->overrides, $user, $this->bulk);
        $this->overrideRecords = [...$this->overrideRecords, ...$checked['overrides']];

        return $checked['ready'];
    }

    /**
     * The selected items among the open ones. A bulk accept leaves those that
     * came to need an individual look since they were selected.
     *
     * @param  Collection<int, AiChangesetItem>  $open
     * @param  list<string>  $selectedIds
     * @return Collection<int, AiChangesetItem>
     */
    private function eligible(Collection $open, array $selectedIds): Collection
    {
        return $open->whereIn('id', $selectedIds)
            ->reject(fn (AiChangesetItem $item) => $this->bulk && DecidableItems::needsIndividualLook($item))
            ->values();
    }

    /**
     * Every item a decision on these items may read or change: themselves,
     * their asset's other items when a bundle is involved, and the proposals
     * they refer to, up through proposed parent folders. Read before the
     * transaction: IDs and references of an item never change, and the
     * transaction's first statements have to be its locks.
     *
     * @param  Collection<int, AiChangesetItem>  $items
     * @return list<string>
     */
    private function lockSet(Collection $items): array
    {
        $ids = $items->pluck('id')->flip();

        if ($items->contains(fn (AiChangesetItem $item) => \is_string($item->review['bundle'] ?? null))) {
            $ids = $ids->merge(AiChangesetItem::query()
                ->whereIn('changeset_id', $items->pluck('changeset_id')->unique()->values())
                ->where('target_type', AiChangesetItem::TARGET_ASSET)
                ->where('target_id', $items->first()->target_id)
                ->pluck('id')
                ->flip());
        }

        $references = fn (AiChangesetItem $item) => collect([...AssetReview::refs($item), \is_array($item->after) ? ($item->after['parent'] ?? null) : null])
            ->map(fn (mixed $ref) => AssetReview::refItemId($ref))
            ->filter()
            ->all();

        $next = $items->flatMap($references)->unique();
        while ($next->isNotEmpty()) {
            $found = AiChangesetItem::query()->whereKey($next->all())->get(['id', 'path', 'after']);
            $ids = $ids->merge($found->pluck('id')->flip());
            $next = $found->flatMap($references)->unique()->reject(fn (string $id) => $ids->has($id));
        }

        return $ids->keys()->all();
    }

    /**
     * Run one decision. Counters and loaded folders and tags start over with
     * every attempt, and a rollback leaves nothing of it behind.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function decide(callable $callback): mixed
    {
        $counters = [$this->accepted, $this->conflicts, $this->blocked];
        $restore = function () use ($counters): void {
            [$this->accepted, $this->conflicts, $this->blocked] = $counters;
            $this->structure->forget();
        };

        try {
            return DecidableItems::transaction(function () use ($callback, $restore) {
                $restore();

                return $callback();
            });
        } catch (\Throwable $e) {
            $restore();

            throw $e;
        }
    }
}
