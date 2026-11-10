<?php

namespace App\Services\Ai\Operations;

use App\Actions\Ai\AcceptAiChangesetItems;
use App\Actions\Ai\DecidableItems;
use App\Actions\Ai\RejectAiChangesetItems;
use App\Enums\AiChangesetItemStatus;
use App\Http\Resources\Management\Ai\AiChangesetItemResource;
use App\Jobs\DecideAiOperationGroupJob;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\User;
use App\Services\Ai\Assets\AssetReview;
use App\Services\Ai\Assets\AssetReviewGroups;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Ai\Translation\TranslationLineage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What a person sees of an operation's changeset. Content is in its drafts, so
 * {@see changes()} lists it per entry, conflicts first. Those are the only things
 * that need a person, and a conflict is resolved on its item.
 *
 * Everything else is a proposal, in groups: by operation, target type, field path
 * (block and entry IDs folded to `*`) and field type, so "Value of 2,000 data
 * entries" is one group with a few examples. Asset proposals group by the review
 * label the asset tool gave them, so a move and its metadata share a group, and
 * new folders and tags form one group listed first.
 *
 * A group accept takes only pending items that need no individual look, see
 * {@see DecidableItems::needsIndividualLook()}, and a move with its metadata
 * together or not at all. Conflicts, flagged items and new folders and tags
 * stay for item-by-item review through the changeset endpoints.
 * Decisions over more than `ai.operations.review_sync_limit` items run on
 * the queue in bounded slices ({@see DecideAiOperationGroupJob}).
 */
final class OperationReview
{
    public const EXAMPLES = 3;

    /** Items one accept or reject call takes; a target's items stay in one call. */
    private const CHUNK = 200;

    /** How long a queued decision shows without a slice renewing it, so a lost job does not show forever. */
    private const QUEUED_SECONDS = 600;

    public function __construct(
        private readonly AcceptAiChangesetItems $accept,
        private readonly RejectAiChangesetItems $reject,
        private readonly TranslationLineage $lineage,
        private readonly AiThreads $threads,
    ) {}

    /**
     * `label` is the asset review label, null for field groups and the structure group.
     * `queued` is `accept` or `reject` while a queued decision works on the group.
     *
     * @return list<array{key: string, label: string|null, operation: string, target_type: string, path: string|null, field_type: string|null, counts: array<string, int>, bulk: int, individual: int, queued: string|null, examples: list<array<string, mixed>>}>
     */
    public function groups(AiOperation $operation): array
    {
        $rows = $this->rows($operation);
        // Examples show what a group accept takes first, then what it leaves to a person.
        $order = fn (object $row) => $row->bulk ? 0 : ($row->individual ? 1 : 2);
        $examples = AiChangesetItem::query()
            ->whereIn('id', $rows->groupBy('group')->flatMap(fn (Collection $group) => $group
                ->sortBy($order)
                ->take(self::EXAMPLES)
                ->pluck('id'))->all())
            ->get()
            ->keyBy('id');
        $lineage = $this->lineage->forItems($examples->keys()->all());

        return $rows->groupBy('group')->map(function (Collection $group, string $key) use ($operation, $examples, $lineage, $order) {
            $first = $group->first();

            return [
                'key' => $key,
                'label' => $first->label,
                'operation' => $first->operation,
                'target_type' => $first->target_type,
                'path' => $first->normalized,
                'field_type' => $first->field_type,
                'counts' => collect(AiChangesetItemStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $group->where('status', $status->value)->count()])->all(),
                'bulk' => $group->filter(fn (object $row) => $row->bulk)->count(),
                'individual' => $group->filter(fn (object $row) => $row->individual)->count(),
                'queued' => self::queued($operation, $key),
                'examples' => $group->sortBy($order)
                    ->take(self::EXAMPLES)
                    ->map(fn (object $row) => (new AiChangesetItemResource($examples[$row->id], $lineage[$row->id] ?? []))->resolve())
                    ->values()->all(),
            ];
        })->sortByDesc(fn (array $group) => $group['counts'][AiChangesetItemStatus::Pending->value] + $group['counts'][AiChangesetItemStatus::Conflict->value])
            // New folders and tags are approved before the assets that use them.
            ->sortBy(fn (array $group) => $group['key'] === AssetReviewGroups::STRUCTURE_GROUP ? 0 : 1)
            ->values()->all();
    }

    /**
     * Items of one proposal group, for inspecting individual changes; `individual`
     * lists only the ones a group accept leaves to a person.
     *
     * @return array{items: Collection<int, AiChangesetItem>, total: int}
     */
    public function items(AiOperation $operation, string $group, int $page, int $perPage, bool $individual = false): array
    {
        $ids = $this->rows($operation)->where('group', $group)
            ->when($individual, fn (Collection $rows) => $rows->filter(fn (object $row) => $row->individual))
            ->pluck('id');

        return [
            'items' => AiChangesetItem::query()->whereIn('id', $ids->forPage($page, $perPage)->all())->orderBy('target_label')->orderBy('id')->get(),
            'total' => $ids->count(),
        ];
    }

    /**
     * What the operation changed in drafts: the figures for the receipt and a page
     * of entries, those with conflicts first, then by name.
     *
     * @return array{summary: array{changed: int, conflicts: int}, entries: list<array{target_id: string|null, label: string|null, written: int, conflicts: int}>, total: int}
     */
    public function changes(AiOperation $operation, int $page, int $perPage): array
    {
        $entries = $this->contentItems($operation)
            ->groupBy(fn (AiChangesetItem $item) => $item->target_id ?? $item->id)
            ->map(fn (Collection $items) => [
                'target_id' => $items->first()->target_id,
                'label' => $items->first()->target_label,
                'written' => $items->filter(fn (AiChangesetItem $item) => $item->status === AiChangesetItemStatus::Accepted)->count(),
                'conflicts' => $items->filter(fn (AiChangesetItem $item) => $item->status === AiChangesetItemStatus::Conflict)->count(),
            ])
            ->values();

        return [
            'summary' => [
                'changed' => $entries->where('written', '>', 0)->count(),
                'conflicts' => $entries->sum('conflicts'),
            ],
            'entries' => $entries
                ->sortBy([['conflicts', 'desc'], ['label', 'asc']])
                ->forPage($page, $perPage)
                ->values()
                ->all(),
            'total' => $entries->count(),
        ];
    }

    /**
     * Content items of one entry, conflicts first.
     *
     * @return array{items: Collection<int, AiChangesetItem>, total: int}
     */
    public function entryItems(AiOperation $operation, string $targetId, int $page, int $perPage): array
    {
        $changeset = $operation->changeset;
        $items = ! $changeset instanceof AiChangeset ? collect() : AiChangesetItem::query()
            ->where('changeset_id', $changeset->id)
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('target_id', $targetId)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (AiChangesetItem $item) => $item->status === AiChangesetItemStatus::Conflict ? 0 : 1)
            ->values();

        return ['items' => $items->forPage($page, $perPage)->values(), 'total' => $items->count()];
    }

    /** @return Collection<int, AiChangesetItem> */
    private function contentItems(AiOperation $operation): Collection
    {
        $changeset = $operation->changeset;

        return ! $changeset instanceof AiChangeset ? collect() : AiChangesetItem::query()
            ->where('changeset_id', $changeset->id)
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->orderBy('id')
            ->get(['id', 'target_type', 'target_id', 'target_label', 'status']);
    }

    /**
     * Accept the items of a group that need no individual look, now or, for
     * a large group, on the queue. `queued` counts the items left to the job.
     *
     * @return array{accepted: int, conflicts: int, failed: int, blocked: int, individual: int, queued: int}
     */
    public function acceptGroup(AiOperation $operation, Space $space, User $user, string $group): array
    {
        $rows = $this->rows($operation)->where('group', $group);
        $individual = $rows->filter(fn (object $row) => $row->individual)->count();
        $bulk = $rows->filter(fn (object $row) => $row->bulk)->count();

        if ($bulk > (int) config('ai.operations.review_sync_limit')) {
            self::markQueued($operation, $group, true);
            DecideAiOperationGroupJob::dispatch($space->id, $operation->id, $user->id, $group, true);

            return ['accepted' => 0, 'conflicts' => 0, 'failed' => 0, 'blocked' => 0, 'individual' => $individual, 'queued' => $bulk];
        }

        $done = $this->acceptSlice($operation, $space, $user, $group);

        return [...array_diff_key($done, ['remaining' => 0]), 'individual' => $individual, 'queued' => 0];
    }

    /**
     * Reject every open item of a group, conflicts and flagged ones included,
     * now or, for a large group, on the queue.
     *
     * @return array{rejected: int, queued: int}
     */
    public function rejectGroup(AiOperation $operation, Space $space, User $user, string $group): array
    {
        $open = $this->rows($operation)->where('group', $group)->filter(fn (object $row) => $row->open)->count();

        if ($open > (int) config('ai.operations.review_sync_limit')) {
            self::markQueued($operation, $group, false);
            DecideAiOperationGroupJob::dispatch($space->id, $operation->id, $user->id, $group, false);

            return ['rejected' => 0, 'queued' => $open];
        }

        return ['rejected' => $this->rejectSlice($operation, $space, $user, $group)['rejected'], 'queued' => 0];
    }

    /**
     * Accept bulk items of the group chunk by chunk until done or past the
     * deadline. Safe to repeat: decided items are no longer pending.
     *
     * @return array{accepted: int, conflicts: int, failed: int, blocked: int, remaining: int}
     */
    public function acceptSlice(AiOperation $operation, Space $space, User $user, string $group, ?float $deadline = null): array
    {
        $chunks = $this->chunks($this->rows($operation)->where('group', $group)->filter(fn (object $row) => $row->bulk));
        $result = ['accepted' => 0, 'conflicts' => 0, 'failed' => 0, 'blocked' => 0, 'remaining' => 0];

        foreach ($chunks as $index => $chunk) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $result['remaining'] = $chunks->slice($index)->sum(fn (array $ids) => \count($ids));

                break;
            }

            $done = $this->accept->handle($operation->changeset, $space, $user, $chunk, bulk: true);
            $result['accepted'] += $done['accepted'];
            $result['conflicts'] += $done['conflicts'];
            $result['failed'] += $done['failed'];
            $result['blocked'] += $done['blocked'];
        }

        $this->threads->changed($operation->space_id, $operation->conversation_id, activity: false);

        return $result;
    }

    /** @return array{rejected: int, remaining: int} */
    public function rejectSlice(AiOperation $operation, Space $space, User $user, string $group, ?float $deadline = null): array
    {
        $chunks = $this->chunks($this->rows($operation)->where('group', $group)->filter(fn (object $row) => $row->open));
        $result = ['rejected' => 0, 'remaining' => 0];

        foreach ($chunks as $index => $chunk) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $result['remaining'] = $chunks->slice($index)->sum(fn (array $ids) => \count($ids));

                break;
            }

            $result['rejected'] += $this->reject->handle($operation->changeset, $space, $user, $chunk);
        }

        $this->threads->changed($operation->space_id, $operation->conversation_id, activity: false);

        return $result;
    }

    /** Show a queued decision on its group; each slice renews it. */
    public static function markQueued(AiOperation $operation, string $group, bool $accept): void
    {
        Cache::put(self::queuedKey($operation->id, $group), $accept ? 'accept' : 'reject', self::QUEUED_SECONDS);
    }

    /** The queued decision is done, or failed. */
    public static function clearQueued(string $operationId, string $group): void
    {
        Cache::forget(self::queuedKey($operationId, $group));
    }

    private static function queued(AiOperation $operation, string $group): ?string
    {
        $decision = Cache::get(self::queuedKey($operation->id, $group));

        return \is_string($decision) ? $decision : null;
    }

    private static function queuedKey(string $operationId, string $group): string
    {
        return "ai-operation-review-queued:{$operationId}:".sha1($group);
    }

    /** Group key of a field path: IDs folded to `*`. */
    public static function normalizePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return implode('.', array_map(
            fn (string $segment) => preg_match('/^([0-9a-hjkmnp-tv-z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $segment) ? '*' : $segment,
            explode('.', $path),
        ));
    }

    /**
     * Item IDs in chunks that keep each target's items together: one save
     * per entry, and a move with its metadata in the same accept.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, list<string>>
     */
    private function chunks(Collection $rows): Collection
    {
        $chunks = [];
        $current = [];

        foreach ($rows->groupBy(fn (object $row) => "{$row->target_type}:".($row->target_id ?? $row->id)) as $target) {
            if ($current !== [] && \count($current) + $target->count() > self::CHUNK) {
                $chunks[] = $current;
                $current = [];
            }
            array_push($current, ...$target->pluck('id')->all());
        }

        return collect($current === [] ? $chunks : [...$chunks, $current]);
    }

    /**
     * One light row per proposal of the operation's changeset, with its group.
     * Content is in its drafts and not decided in groups.
     *
     * @return Collection<int, object{id: string, operation: string, target_type: string, target_id: string|null, path: string|null, field_type: string|null, status: string, normalized: string|null, group: string, label: string|null, bundle: string|null, open: bool, bulk: bool, individual: bool}>
     */
    private function rows(AiOperation $operation): Collection
    {
        $changeset = $operation->changeset;
        if (! $changeset instanceof AiChangeset) {
            return collect();
        }

        $rows = AiChangesetItem::query()
            ->where('changeset_id', $changeset->id)
            ->where('target_type', '!=', AiChangesetItem::TARGET_CONTENT)
            ->orderBy('id')
            ->get(['id', 'operation', 'target_type', 'target_id', 'path', 'field_type', 'status', 'flags', 'review'])
            ->map(function (AiChangesetItem $item) {
                $normalized = self::normalizePath($item->path);
                $label = null;
                if (\in_array($item->target_type, AssetReview::STRUCTURE_TYPES, true)) {
                    $group = AssetReviewGroups::STRUCTURE_GROUP;
                } elseif (AssetReview::handles($item->target_type) && \is_string($item->review['group'] ?? null)) {
                    $label = $item->review['group'];
                    $group = "asset|{$label}";
                } else {
                    $group = implode('|', [$item->operation, $item->target_type, $normalized ?? '', $item->field_type ?? '']);
                }
                $needsLook = DecidableItems::needsIndividualLook($item);
                $open = $item->isOpen();

                return (object) [
                    'id' => $item->id,
                    'operation' => $item->operation,
                    'target_type' => $item->target_type,
                    'target_id' => $item->target_id,
                    'path' => $item->path,
                    'field_type' => $item->field_type,
                    'status' => $item->status->value,
                    'normalized' => $normalized,
                    'group' => $group,
                    'label' => $label,
                    'bundle' => \is_string($item->review['bundle'] ?? null) ? $item->review['bundle'] : null,
                    'open' => $open,
                    'bulk' => $open && ! $needsLook,
                    'individual' => $open && $needsLook,
                ];
            });

        // A move and its metadata are accepted together: one member left to a person keeps the others with it.
        $held = $rows->filter(fn (object $row) => $row->bundle !== null && $row->individual)->pluck('bundle')->flip();
        if ($held->isNotEmpty()) {
            $rows->each(function (object $row) use ($held) {
                if ($row->bulk && isset($held[$row->bundle ?? ''])) {
                    $row->bulk = false;
                    $row->individual = true;
                }
            });
        }

        return $rows;
    }
}
