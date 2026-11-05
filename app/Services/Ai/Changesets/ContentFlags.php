<?php

namespace App\Services\Ai\Changesets;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiRun;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Threads\AiThreads;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * What AI left on an entry for a person: open conflicts, and flagged wording that
 * is still in the draft. `open_findings` on an item counts its flagged wording. It
 * is set when the item is written and lowered whenever the entry's version moves,
 * so a person's edit that removes the wording clears the flag with nothing to
 * dismiss, and reading flags opens no document.
 *
 * Publishing what stands in the draft clears its flags: publication is the review.
 * A cleared flag stays cleared, also when an older version comes back.
 */
final class ContentFlags
{
    public function __construct(private readonly AiThreads $threads) {}

    /** How many blocking findings a newly written item leaves open. */
    public static function openCount(AiChangesetItem $item): int
    {
        return $item->target_type === AiChangesetItem::TARGET_CONTENT
            ? \count(GlossaryChecker::blocking($item->glossary_findings ?? []))
            : 0;
    }

    /**
     * The newest write of a field speaks for it: flags of earlier writes close, so
     * wording that stayed through a correction counts once.
     *
     * @param  Collection<int, AiChangesetItem>  $written
     */
    public function supersede(Collection $written): void
    {
        foreach ($written->where('target_type', AiChangesetItem::TARGET_CONTENT)->whereNotNull('path') as $item) {
            $earlier = AiChangesetItem::query()
                ->where('target_type', AiChangesetItem::TARGET_CONTENT)
                ->where('target_id', $item->target_id)
                ->where('open_findings', '>', 0)
                ->whereKeyNot($item->id)
                ->get(['id', 'path'])
                ->filter(fn (AiChangesetItem $other) => $other->path !== null && FieldPath::overlap($other->path, (string) $item->path));

            if ($earlier->isNotEmpty()) {
                AiChangesetItem::query()->whereKey($earlier->modelKeys())->update(['open_findings' => 0]);
            }
        }
    }

    /** Called when an entry's current or published version moved. One indexed read, usually empty. */
    public function versionMoved(Content $content): void
    {
        $items = $this->openItems($content->id);
        if ($items->isEmpty()) {
            return;
        }

        $changed = $content->current_version_id === $content->published_version_id
            ? $this->close($items)
            : $this->recheck($items, $this->documentOf($content));

        $this->tellThreads($changed);
    }

    /** The entry is gone, so nothing is left to publish. */
    public function deleted(Content $content): void
    {
        $this->tellThreads($this->close($this->openItems($content->id)));
    }

    /**
     * Figures per entry for the tree and for publish counts. Two index range reads
     * that touch only flagged rows, so their cost follows the flags, not the space.
     *
     * @param  list<string>|null  $ids  only these entries
     * @return array<string, array{conflicts: int, flagged: int}>
     */
    public function counts(?array $ids = null): array
    {
        $flagged = AiChangesetItem::query()->toBase()
            ->where('open_findings', '>', 0)
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->when($ids !== null, fn ($query) => $query->whereIn('target_id', $ids))
            ->groupBy('target_id')
            ->selectRaw('target_id, sum(open_findings) as total')
            ->pluck('total', 'target_id');

        $conflicts = AiChangesetItem::query()->toBase()
            ->where('status', AiChangesetItemStatus::Conflict->value)
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->whereNotNull('target_id')
            ->when($ids !== null, fn ($query) => $query->whereIn('target_id', $ids))
            ->groupBy('target_id')
            ->selectRaw('target_id, count(*) as total')
            ->pluck('total', 'target_id');

        $counts = [];
        foreach ($flagged->keys()->merge($conflicts->keys())->unique() as $id) {
            $counts[(string) $id] = ['conflicts' => (int) ($conflicts[$id] ?? 0), 'flagged' => (int) ($flagged[$id] ?? 0)];
        }

        return $counts;
    }

    /**
     * What stands on some entries, named: the wording, the field and where to go.
     *
     * @param  list<string>  $ids
     * @return list<array{content_id: string, name: string, language_iso: string|null, flags: list<array{kind: string, path: string|null, field: string|null, wording?: string, replacement?: string, thread_id?: string|null}>}>
     */
    public function details(array $ids): array
    {
        $items = AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->whereIn('target_id', $ids)
            ->where(fn ($query) => $query->where('open_findings', '>', 0)->orWhere('status', AiChangesetItemStatus::Conflict->value))
            ->orderBy('id')
            ->get(['id', 'changeset_id', 'target_type', 'target_id', 'path', 'status', 'glossary_findings', 'open_findings']);
        if ($items->isEmpty()) {
            return [];
        }

        $contents = Content::query()->with(['block', 'current_version'])->whereIn('id', $items->pluck('target_id')->unique())->get()->keyBy('id');
        $threads = $this->threadIds($items->pluck('changeset_id')->unique()->values()->all());

        return $items->groupBy('target_id')
            ->filter(fn (Collection $group, string $id) => $contents->has($id))
            ->map(function (Collection $group, string $id) use ($contents, $threads) {
                /** @var Content $content */
                $content = $contents[$id];
                $flags = $group->flatMap(function (AiChangesetItem $item) use ($content, $threads) {
                    $field = $this->fieldLabel($content, $item->path);
                    if ($item->status === AiChangesetItemStatus::Conflict) {
                        return [['kind' => 'conflict', 'path' => $item->path, 'field' => $field, 'thread_id' => $threads[$item->changeset_id] ?? null]];
                    }

                    return array_map(fn (array $finding) => array_filter([
                        'kind' => 'flagged',
                        'path' => $item->path,
                        'field' => $field,
                        'wording' => self::wording($finding),
                        'replacement' => isset($finding['found']) ? ($finding['expected'] ?? null) : null,
                    ], fn ($value) => $value !== null), $item->standingFindings());
                });

                return [
                    'content_id' => $id,
                    'name' => (string) $content->name,
                    'language_iso' => $content->language_iso,
                    'flags' => $flags->unique(fn (array $flag) => implode("\0", [$flag['kind'], $flag['path'] ?? '', $flag['wording'] ?? '']))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The words a finding is about: the wording found, or the name that was changed.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function wording(array $finding): string
    {
        return (string) ($finding['found'] ?? $finding['expected'] ?? $finding['term'] ?? '');
    }

    /** @return EloquentCollection<int, AiChangesetItem> */
    private function openItems(string $contentId): EloquentCollection
    {
        return AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('target_id', $contentId)
            ->where('open_findings', '>', 0)
            ->get(['id', 'changeset_id', 'path', 'glossary_findings', 'open_findings']);
    }

    /**
     * @param  EloquentCollection<int, AiChangesetItem>  $items
     * @return list<string> changesets whose flags changed
     */
    private function close(EloquentCollection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        AiChangesetItem::query()->whereKey($items->modelKeys())->update(['open_findings' => 0]);

        return $items->pluck('changeset_id')->unique()->values()->all();
    }

    /**
     * Mark each finding whose wording left its field. An item keeps the findings
     * for its history, so `cleared` says which of them still stand.
     *
     * @param  EloquentCollection<int, AiChangesetItem>  $items
     * @param  array<string, mixed>  $document
     * @return list<string> changesets whose flags changed
     */
    private function recheck(EloquentCollection $items, array $document): array
    {
        $changed = [];
        foreach ($items as $item) {
            $value = $item->path !== null ? FieldPath::get($document, $item->path) : $document;
            $text = implode("\n", array_filter(Arr::flatten(Arr::wrap($value)), 'is_string'));
            $open = 0;

            $findings = array_map(function (array $finding) use ($text, &$open) {
                if (! GlossaryChecker::isBlocking($finding) || ($finding['cleared'] ?? false)) {
                    return $finding;
                }
                if (self::stands($finding, $text)) {
                    $open++;

                    return $finding;
                }

                return [...$finding, 'cleared' => true];
            }, $item->glossary_findings ?? []);

            if ($open !== $item->open_findings) {
                $item->update(['glossary_findings' => $findings, 'open_findings' => $open]);
                $changed[] = $item->changeset_id;
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * Banned wording stands while it occurs, a protected name while it does not.
     * Banned wording matches in any case: changing only the case does not remove it.
     *
     * @param  array<string, mixed>  $finding
     */
    private static function stands(array $finding, string $text): bool
    {
        return isset($finding['found'])
            ? GlossaryMatcher::contains($text, (string) $finding['found'], false)
            : ! GlossaryMatcher::contains($text, (string) ($finding['expected'] ?? ''), true);
    }

    /**
     * Read fresh: the model's loaded relation may be the version before this move.
     *
     * @return array<string, mixed>
     */
    private function documentOf(Content $content): array
    {
        $document = ContentVersion::query()->whereKey($content->current_version_id)->first()?->content;

        return \is_array($document) ? $document : [];
    }

    private function fieldLabel(Content $content, ?string $path): ?string
    {
        if ($path === null || $content->block === null) {
            return null;
        }

        try {
            $field = app(ContentTarget::class)->fieldAt($content->block, $content->current_version->content ?? [], $path);

            return (string) ($field['field']['name'] ?? $path);
        } catch (ToolError) {
            return $path;
        }
    }

    /** @param list<string> $changesetIds */
    private function tellThreads(array $changesetIds): void
    {
        foreach (AiChangeset::query()->whereKey($changesetIds)->get(['id', 'space_id', 'run_id', 'operation_id']) as $changeset) {
            $this->threads->changed($changeset->space_id, $this->threadIds([$changeset->id])[$changeset->id] ?? null, activity: false);
        }
    }

    /**
     * The thread that holds each changeset: an operation's batch runs have none of their own.
     *
     * @param  list<string>  $changesetIds
     * @return array<string, string|null>
     */
    private function threadIds(array $changesetIds): array
    {
        $changesets = AiChangeset::query()->whereKey($changesetIds)->get(['id', 'run_id', 'operation_id']);
        $operations = AiOperation::query()->whereKey($changesets->pluck('operation_id')->filter()->unique()->values()->all())->pluck('conversation_id', 'id');
        $runs = AiRun::query()->whereKey($changesets->pluck('run_id')->filter()->unique()->values()->all())->pluck('conversation_id', 'id');

        return $changesets->mapWithKeys(fn (AiChangeset $changeset) => [
            $changeset->id => $changeset->operation_id !== null ? ($operations[$changeset->operation_id] ?? null) : ($runs[$changeset->run_id] ?? null),
        ])->all();
    }
}
