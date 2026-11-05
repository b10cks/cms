<?php

namespace App\Services\Ai\Translation;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\TranslationSource;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which source text each translated value was written against.
 *
 * An AI translation records its source when it is written, and keeps it when
 * someone edits a conflict before applying it. Any other value (typed
 * by an editor, imported) gets its lineage from history: the value was
 * written against the source as it stood when the value first appeared in
 * the translation's current streak of versions. Derived lineage is stored, so
 * history is read once per value.
 */
class TranslationLineage
{
    /** Versions of a translation read per query when deriving lineage. */
    private const HISTORY_CHUNK = 50;

    /** @var array<string, string|null> source ID and moment => ID of the source version current then */
    private array $sourceVersions = [];

    public function __construct(private readonly TranslationUnits $units) {}

    /**
     * Lineage of each translated unit's value. An AI translation counts only once
     * written: a conflict or a rejected one says nothing about a value that
     * happens to hold the same text.
     *
     * @param  list<array{source: Content, translation: Content, targets: array<string, string>, version?: ContentVersion}>  $entries  targets: unit ID => non-empty text held by `version`, by default the current version
     * @return array<string, array<string, array{source_hash: string, source_text: string, origin: string}>> translation ID => unit ID => lineage, units without a known origin left out
     */
    public function of(array $entries): array
    {
        $entries = array_values(array_filter($entries, fn (array $entry) => $entry['targets'] !== []));
        if ($entries === []) {
            return [];
        }

        $translationIds = array_map(fn (array $entry) => $entry['translation']->id, $entries);
        // A new translation entry gets its row ID only once it is written.
        $created = AiChangesetItem::query()
            ->where('operation', AiChangesetItem::OPERATION_CREATE)
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->whereIn('target_id', $translationIds)
            ->pluck('target_id', 'id');

        $rows = TranslationSource::query()
            ->where(fn ($query) => $query->whereIn('content_id', $translationIds)->orWhereIn('changeset_item_id', $created->keys()->all()))
            ->whereNotExists(fn ($query) => $query->select('id')
                ->from('ai_changeset_items')
                ->whereColumn('ai_changeset_items.id', 'translation_sources.changeset_item_id')
                ->where('ai_changeset_items.status', '!=', AiChangesetItemStatus::Accepted->value))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['content_id', 'changeset_item_id', 'field_path', 'source_hash', 'source_text', 'target_hash', 'origin'])
            ->groupBy(fn (TranslationSource $row) => $row->content_id ?? $created->get($row->changeset_item_id));

        $lineage = [];
        foreach ($entries as $entry) {
            ['source' => $source, 'translation' => $translation, 'targets' => $targets] = $entry;
            $known = [];
            $unknown = [];

            foreach ($targets as $unitId => $text) {
                $hash = TranslationMemory::hash($text);
                $row = $rows->get($translation->id, collect())
                    ->first(fn (TranslationSource $row) => $row->field_path === (string) $unitId && $row->target_hash === $hash);

                if ($row) {
                    $known[$unitId] = ['source_hash' => $row->source_hash, 'source_text' => $row->source_text, 'origin' => $row->origin];
                } else {
                    $unknown[$unitId] = $hash;
                }
            }

            $lineage[$translation->id] = [...$known, ...($unknown !== [] ? $this->fromHistory($source, $translation, $unknown, $entry['version'] ?? null) : [])];
        }

        return $lineage;
    }

    /**
     * Store the lineage of AI translations, one row per unit.
     *
     * @param  list<array{content_id: string|null, changeset_item_id: string, source_content_id: string, field_path: string, source_version_id: string|null, source_text: string, target_text: string, reason: string, previous_source_text: string|null}>  $rows
     */
    public function recordProposals(array $rows): void
    {
        $this->insert(array_map(fn (array $row) => [
            ...Arr::except($row, 'target_text'),
            'source_hash' => TranslationMemory::hash($row['source_text']),
            'target_hash' => TranslationMemory::hash($row['target_text']),
            'origin' => TranslationSource::ORIGIN_AI,
        ], $rows));
    }

    /**
     * What people need beside an AI translation item: per unit why it was
     * written, the source it was translated from and, for stale units, the
     * source text the replaced translation was made from.
     *
     * @param  list<string>  $itemIds
     * @return array<string, list<array{unit_id: string, reason: string|null, source: string, previous_source: string|null}>> item ID => units
     */
    public function forItems(array $itemIds): array
    {
        return TranslationSource::query()
            ->whereIn('changeset_item_id', $itemIds)
            ->where('origin', TranslationSource::ORIGIN_AI)
            ->orderBy('field_path')
            ->get(['changeset_item_id', 'field_path', 'reason', 'source_text', 'previous_source_text'])
            ->groupBy('changeset_item_id')
            ->map(fn (Collection $rows) => $rows->map(fn (TranslationSource $row) => [
                'unit_id' => $row->field_path,
                'reason' => $row->reason,
                'source' => $row->source_text,
                'previous_source' => $row->previous_source_text,
            ])->values()->all())
            ->all();
    }

    /**
     * Store the lineage of proposals a person edited, so an edited value
     * keeps the source it was translated from instead of being dated at
     * acceptance. Call after the edits are applied.
     *
     * @param  Collection<int, AiChangesetItem>  $items  accepted items with a reviewer edit
     */
    public function recordEdits(Collection $items): void
    {
        $proposed = $items->isEmpty() ? collect() : TranslationSource::query()
            ->whereIn('changeset_item_id', $items->pluck('id')->all())
            ->where('origin', TranslationSource::ORIGIN_AI)
            ->get();
        if ($proposed->isEmpty()) {
            return;
        }

        $targets = $items->keyBy('id')->map(fn (AiChangesetItem $item) => $item->target_id);
        $texts = Content::query()
            ->with(['current_version', 'block'])
            ->whereIn('id', $targets->filter()->unique()->values()->all())
            ->get()
            ->mapWithKeys(fn (Content $content) => [$content->id => $this->units->texts($content->getCurrentContent(), $content->block?->schema?->toArray() ?? [])]);

        $rows = [];
        foreach ($proposed as $row) {
            $contentId = $targets->get($row->changeset_item_id);
            $text = $texts->get($contentId, [])[$row->field_path] ?? '';
            $hash = TranslationMemory::hash($text);
            if (TranslationUnits::isBlank($text) || $hash === $row->target_hash) {
                continue;
            }

            $rows[] = [
                ...$row->only(['changeset_item_id', 'source_content_id', 'field_path', 'source_version_id', 'source_hash', 'source_text', 'reason', 'previous_source_text']),
                'content_id' => $contentId,
                'target_hash' => $hash,
                'origin' => TranslationSource::ORIGIN_REVIEW,
            ];
        }

        $this->insert($rows);
    }

    /**
     * Lineage from the translation's history. The versions are read newest
     * first, a chunk at a time from the given version back, until each
     * value's streak of versions ends or the history does. Units whose
     * origin has no date get no lineage.
     *
     * @param  array<string, string>  $targets  unit ID => hash of the value in $from
     * @param  ContentVersion|null  $from  the version holding the values, by default the current one
     * @return array<string, array{source_hash: string, source_text: string, origin: string}>
     */
    private function fromHistory(Content $source, Content $translation, array $targets, ?ContentVersion $from): array
    {
        $schema = $source->block?->schema?->toArray() ?? [];

        // When each value first appeared in the streak of versions that still hold it.
        $since = [];
        $open = $targets;
        $chunk = collect([$from ?? $translation->loadMissing('current_version')->current_version])->filter();
        while ($chunk->isNotEmpty()) {
            foreach ($chunk as $version) {
                $texts = $this->units->texts($version->content ?? [], $schema);
                foreach ($open as $unitId => $hash) {
                    if (TranslationMemory::hash($texts[$unitId] ?? '') === $hash) {
                        $since[$unitId] = $version->created_at;
                    } else {
                        unset($open[$unitId]);
                    }
                }
                if ($open === []) {
                    break 2;
                }
            }

            $last = $chunk->last();
            $chunk = $last->created_at === null ? collect() : ContentVersion::query()
                ->where('content_id', $translation->id)
                ->where(fn ($query) => $query->where('created_at', '<', $last->created_at)
                    ->orWhere(fn ($query) => $query->where('created_at', $last->created_at)->where('id', '<', $last->id)))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::HISTORY_CHUNK)
                ->get(['id', 'created_at', 'content']);
        }

        $sourceTexts = [];
        $lineage = [];
        $rows = [];
        foreach (array_intersect_key($targets, array_filter($since)) as $unitId => $hash) {
            $versionId = $this->sourceVersionAt($source, $since[$unitId]);
            $sourceTexts[$versionId ?? ''] ??= $this->units->texts($versionId ? (ContentVersion::query()->find($versionId, ['id', 'content'])?->content ?? []) : [], $schema);
            $text = $sourceTexts[$versionId ?? ''][$unitId] ?? '';

            $lineage[$unitId] = ['source_hash' => TranslationMemory::hash($text), 'source_text' => $text, 'origin' => TranslationSource::ORIGIN_HISTORY];
            $rows[] = [
                'content_id' => $translation->id,
                'changeset_item_id' => null,
                'source_content_id' => $source->id,
                'field_path' => (string) $unitId,
                'source_version_id' => $versionId,
                'source_hash' => $lineage[$unitId]['source_hash'],
                'source_text' => $text,
                'target_hash' => $hash,
                'origin' => TranslationSource::ORIGIN_HISTORY,
                'reason' => null,
                'previous_source_text' => null,
            ];
        }

        $this->insert($rows);

        return $lineage;
    }

    /** The ID of the source's version that was current at a moment, else of its first version. */
    private function sourceVersionAt(Content $source, Carbon $at): ?string
    {
        $versions = fn () => ContentVersion::query()->where('content_id', $source->id);

        return $this->sourceVersions["{$source->id}@{$at->toJSON()}"] ??= $versions()
            ->where('created_at', '<=', $at)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id') ?? $versions()->orderBy('created_at')->orderBy('id')->value('id');
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insert(array $rows): void
    {
        $now = now();

        foreach (array_chunk($rows, 200) as $chunk) {
            TranslationSource::query()->insert(array_map(fn (array $row) => [
                'id' => strtolower((string) Str::ulid()),
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }
}
