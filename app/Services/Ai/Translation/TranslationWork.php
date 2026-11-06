<?php

namespace App\Services\Ai\Translation;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\Content;
use App\Models\Space\TranslationSource;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Metadata\MetadataFields;

/**
 * Decides, per source entry and locale, which units need translating and
 * why: the translation is missing, a field is empty with no fallback locale
 * to fill it, the source changed since the field was translated (lineage),
 * or the field still holds the source text. Everything else is held, so
 * human translations of unchanged source text are never written over.
 */
class TranslationWork
{
    public function __construct(
        private readonly TranslationUnits $units,
        private readonly TranslationLineage $lineage,
        private readonly MetadataFields $metadata,
    ) {}

    /**
     * @param  list<TranslationDestination>  $destinations
     * @param  AiRun|null  $run  the task: a row it created still owes the units it was created without, as missing work
     * @return list<TranslationTask>
     */
    public function tasks(Space $space, array $destinations, TranslationPlan $plan, ?AiRun $run = null): array
    {
        if ($destinations === []) {
            return [];
        }

        $sources = [];
        $generated = [];
        foreach ($destinations as $destination) {
            $sources[$destination->source->id] ??= $this->units->collect($destination->source->getCurrentContent(), $this->schema($destination));
            if ($plan->metadata !== null) {
                $generated[$destination->source->id] ??= array_column($this->metadata->planned($plan->metadata, $destination->source->getCurrentContent(), $this->schema($destination)), 'path');
            }
        }

        $fallbacks = $this->fallbackTexts($space, $destinations);
        $created = $this->createdRows($destinations, $run);

        $targets = [];
        foreach ($destinations as $destination) {
            if ($destination->translation) {
                $targets[$destination->translation->id] = $this->units->texts($destination->translation->getCurrentContent(), $this->schema($destination));
            }
        }

        $lineage = $this->lineage->of(array_values(array_filter(array_map(fn (TranslationDestination $destination) => $destination->translation ? [
            'source' => $destination->source,
            'translation' => $destination->translation,
            'targets' => array_filter($targets[$destination->translation->id], fn (string $text) => ! TranslationUnits::isBlank($text)),
        ] : null, $destinations))));

        return array_map(function (TranslationDestination $destination) use ($space, $sources, $generated, $fallbacks, $created, $targets, $lineage, $plan) {
            $translation = $destination->translation;
            $units = [];
            $held = [];

            foreach ($sources[$destination->source->id] as $id => $unit) {
                $id = (string) $id;
                if (TranslationUnits::isBlank($unit['value'])) {
                    continue;
                }

                $field = TranslationUnits::fieldPath($id, $unit['type']);
                if ($unit['type'] === 'meta' && $plan->generatesMetadata($destination->locale) && \in_array($field, $generated[$destination->source->id] ?? [], true) && \in_array(substr($id, \strlen($field) + 1), $plan->metadata['keys'], true)) {
                    $held[$id] = TranslationTask::HELD_GENERATED;

                    continue;
                }

                $current = $translation ? ($targets[$translation->id][$id] ?? '') : '';
                $work = ['id' => $id, 'field' => $field, 'label' => $unit['note'], 'type' => $unit['type'], 'source' => $unit['value'], 'current' => $current];

                [$reason, $previous] = ! $translation
                    ? [TranslationPlan::MISSING, null]
                    : $this->assess($space, $destination, $id, $field, $unit, $current, $fallbacks, $lineage[$translation->id][$id] ?? null);
                // The unit of an entry this task created and has not reached yet: an empty field there is the missing translation it set out to write.
                if ($reason === TranslationPlan::EMPTY && isset($created[$translation?->id])) {
                    $reason = TranslationPlan::MISSING;
                }

                if (! \in_array($reason, TranslationPlan::KINDS, true)) {
                    $held[$id] = $reason;
                } elseif (! $plan->includes($reason)) {
                    $held[$id] = TranslationTask::HELD_NOT_IN_PLAN;
                } else {
                    $units[$id] = [...$work, 'reason' => $reason, ...($previous !== null ? ['previous_source' => $previous] : [])];
                }
            }

            return new TranslationTask($destination, $units, $held, $plan->includes(TranslationPlan::MISSING));
        }, $destinations);
    }

    /**
     * Unit IDs a locale still owes this task: neither written nor settled on their own.
     *
     * @param  list<string>  $settled
     * @return list<string>
     */
    public function remaining(Space $space, TranslationDestination $destination, TranslationPlan $plan, AiRun $run, array $settled): array
    {
        return array_values(array_diff(array_map('strval', array_keys($this->tasks($space, [$destination], $plan, $run)[0]->units)), $settled));
    }

    /**
     * @param  array{type: string, value: string}  $unit
     * @param  array<string, array<string, array<string, string>>>  $fallbacks
     * @param  array{source_hash: string, source_text: string, origin: string}|null  $lineage
     * @return array{0: string, 1: string|null} reason or HELD_* value, previous source text for stale units
     */
    private function assess(Space $space, TranslationDestination $destination, string $id, string $field, array $unit, string $current, array $fallbacks, ?array $lineage): array
    {
        if (TranslationUnits::isBlank($current)) {
            if (! $destination->overlay() && ! $this->holdsItem($destination->translation, $field)) {
                return [TranslationTask::HELD_NOT_IN_TRANSLATION, null];
            }

            foreach ($this->chain($space, $destination->locale) as $language) {
                if (! TranslationUnits::isBlank($fallbacks[$destination->source->id][$language][$id] ?? '')) {
                    return [TranslationTask::HELD_INHERITED, null];
                }
            }

            return [TranslationPlan::EMPTY, null];
        }

        if ($lineage !== null && $lineage['source_hash'] !== TranslationMemory::hash($unit['value'])) {
            return [TranslationPlan::STALE, $lineage['source_text']];
        }

        // An AI translation may rightly equal its source, a brand name above all, and is done.
        if (TranslationMemory::hash($current) === TranslationMemory::hash($unit['value']) && ($lineage['origin'] ?? null) !== TranslationSource::ORIGIN_AI) {
            return [TranslationPlan::SAME_AS_SOURCE, null];
        }

        return [TranslationTask::HELD_CURRENT, null];
    }

    /** Whether the translation holds the block a nested field lives in. Root fields always count as held. */
    private function holdsItem(?Content $translation, string $field): bool
    {
        $item = str_contains($field, '.') ? substr($field, 0, (int) strrpos($field, '.')) : null;

        return $item === null || \is_array(FieldPath::get($translation?->getCurrentContent() ?? [], $item));
    }

    /**
     * Non-default languages a locale falls back to, nearest first.
     *
     * @return list<string>
     */
    private function chain(Space $space, string $locale): array
    {
        $default = $space->settings->getDefaultLanguage();
        $chain = [];
        $language = $space->settings->getFallbackLanguage($locale);

        while ($language !== null && $language !== $default && $language !== $locale && ! \in_array($language, $chain, true)) {
            $chain[] = $language;
            $language = $space->settings->getFallbackLanguage($language);
        }

        return $chain;
    }

    /**
     * Unit texts of the fallback-locale rows: source ID => language => unit ID => text.
     *
     * @param  list<TranslationDestination>  $destinations
     * @return array<string, array<string, array<string, string>>>
     */
    private function fallbackTexts(Space $space, array $destinations): array
    {
        $languages = collect($destinations)->flatMap(fn (TranslationDestination $destination) => $this->chain($space, $destination->locale))->unique()->values()->all();

        if ($languages === []) {
            return [];
        }

        $schemas = collect($destinations)->mapWithKeys(fn (TranslationDestination $destination) => [$destination->source->id => $this->schema($destination)]);
        $texts = [];

        Content::query()
            ->with('current_version')
            ->whereIn('i18n_parent_id', $schemas->keys()->all())
            ->whereIn('language_iso', $languages)
            ->whereNull('deleted_at')
            ->get()
            ->each(function (Content $row) use (&$texts, $schemas) {
                $texts[$row->i18n_parent_id][$row->language_iso] = $this->units->texts($row->getCurrentContent(), $schemas[$row->i18n_parent_id]);
            });

        return $texts;
    }

    /**
     * Translation rows this task created.
     *
     * @param  list<TranslationDestination>  $destinations
     * @return array<string, true>
     */
    private function createdRows(array $destinations, ?AiRun $run): array
    {
        $rows = array_values(array_filter(array_map(fn (TranslationDestination $destination) => $destination->translation?->id, $destinations)));

        return $run === null || $rows === [] ? [] : AiChangesetItem::query()
            ->whereHas('changeset', fn ($changeset) => $changeset->ofRun($run))
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('operation', AiChangesetItem::OPERATION_CREATE)
            ->where('status', AiChangesetItemStatus::Accepted->value)
            ->whereIn('target_id', $rows)
            ->pluck('target_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /** @return array<string, mixed> */
    private function schema(TranslationDestination $destination): array
    {
        return $destination->source->block?->schema?->toArray() ?? [];
    }
}
