<?php

namespace App\Services\Ai\Tools;

use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperationItem;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Translation\TranslationDestination;
use App\Services\Ai\Translation\TranslationDestinations;
use App\Services\Ai\Translation\TranslationPlan;
use App\Services\Ai\Translation\TranslationProposer;
use App\Services\Ai\Translation\TranslationWork;

/**
 * The translation write path shared by `propose_translation` and
 * `propose_create` with `translation_of`. The destination row, the locale
 * and the units that may change are decided here, never by the model. What it
 * writes goes straight to the translation's draft, creating the entry when the
 * locale has none.
 *
 * In an operation batch every write derives from its frozen source entry and the
 * approved locales bound it. The source's locale counts as done
 * ({@see ReportsOutcomes}) only once no unit of it is left to write; until then
 * the ledger keeps the units written and remaining per locale. A unit that hit a
 * conflict becomes its own part, so the rest of the locale goes on, and it stays
 * open for a person: a further write to its field is refused and reported.
 *
 * @mixin ProposalTool
 */
trait ProposesTranslations
{
    /** @var list<array{target_type: string, target_id: string, outcome: string, reason: string|null, part?: string, units?: array{written: list<string>, remaining: list<string>}}> */
    private array $outcomes = [];

    public function takeOutcomes(): array
    {
        [$outcomes, $this->outcomes] = [$this->outcomes, []];

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $texts  unit ID => translated text
     * @return array<string, mixed> the tool result
     *
     * @throws ToolError
     */
    protected function proposeTranslation(
        string $sourceId,
        string $language,
        array $texts,
        ?string $name = null,
        ?string $slug = null,
        ?string $sourceVersionId = null,
        ?string $versionId = null,
        bool $requirePins = true,
        bool $createOnly = false,
    ): array {
        $this->ensureCanWriteContent();
        $plan = TranslationPlan::forRun($this->run);
        $destinations = app(TranslationDestinations::class);
        $destination = $destinations->resolve($this->space, $sourceId, $language, $plan->locales);
        $operation = OperationContext::forRun($this->run);
        $operation?->ensureLocale($destination->locale);
        $item = $operation !== null && ! $operation->isPlanning() ? $operation->item(AiChangesetItem::TARGET_CONTENT, $destination->source->id) : null;
        $this->ensureUnitsOwed($operation, $item, $destination, $texts);
        $translation = $destination->translation;
        if ($createOnly && $translation) {
            throw new ToolError("This entry already has a {$destination->locale} translation ({$translation->id}). Change it with propose_translation.");
        }

        $this->ensureTranslationInScope($destination);

        if (($requirePins || $sourceVersionId !== null) && $sourceVersionId !== $destination->source->current_version_id) {
            throw new ToolError('The source entry changed since you read it, or you passed no source_version_id. Call get_translation_task again and translate from what it returns.');
        }
        if ($translation && ($requirePins || $versionId !== null) && $versionId !== $translation->current_version_id) {
            throw new ToolError("The {$destination->locale} translation was saved since you read it, or you passed no version_id, so nothing was written. Call get_translation_task again: what a person wrote there stays.");
        }

        $work = app(TranslationWork::class);
        [$task] = $work->tasks($this->space, [$destination], $plan, $this->run);
        $proposer = app(TranslationProposer::class);
        $prepared = $proposer->prepare($this->space, $task, $texts, $name, $slug, $this->run);
        $derived = $operation?->derivedFrom(AiChangesetItem::TARGET_CONTENT, $destination->source->id) ?? [];

        $left = $translation ? $this->leftForPerson($translation->id, array_map('strval', array_filter(array_column($prepared->items, 'path')))) : [];
        $leftUnits = $prepared->unitsOf($left);
        $prepared = $prepared->without($left);

        $recorded = [];
        $write = $prepared->items === []
            ? ['written' => collect(), 'conflicts' => collect(), 'version_id' => $translation?->current_version_id]
            : $this->writeContent(function () use ($prepared, $proposer, $derived, &$recorded) {
                $recorded = $this->record(array_map(fn (array $item) => [...$item, ...$derived], $prepared->items));
                $this->addHints($proposer->persist($prepared, $recorded));

                return $recorded;
            });

        // Which units each item carried, in the order they were prepared.
        $conflictIds = $write['conflicts']->pluck('id')->all();
        $writtenUnits = [];
        $conflictUnits = $leftUnits;
        foreach ($recorded as $index => $recordedItem) {
            $units = array_column($prepared->lineage[$index] ?? [], 'field_path');
            \in_array($recordedItem->id, $conflictIds, true) ? array_push($conflictUnits, ...$units) : array_push($writtenUnits, ...$units);
        }

        foreach ($conflictUnits as $unit) {
            $part = AiOperationItem::unitPart($destination->locale, $unit);
            $item?->addUnitPart($part);
            if ($item !== null) {
                $this->outcomes[] = ['target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $destination->source->id, 'outcome' => ReportsOutcomes::CONFLICT, 'reason' => null, 'part' => $part];
            }
        }
        foreach ($writtenUnits as $unit) {
            if (\in_array($part = AiOperationItem::unitPart($destination->locale, $unit), (array) ($item?->detail['unit_parts'] ?? []), true)) {
                $this->outcomes[] = ['target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $destination->source->id, 'outcome' => ReportsOutcomes::WRITTEN, 'reason' => null, 'part' => $part];
            }
        }

        // Read again: the entry may exist only now. Units settled on their own hold the locale open no longer.
        $remaining = array_values(array_diff(
            $work->remaining($this->space, $destinations->resolve($this->space, $sourceId, $language, $plan->locales), $plan, $this->run, $item?->settledUnits($destination->locale) ?? []),
            $conflictUnits,
        ));
        $wrote = $writtenUnits !== [] || ($item?->detail['units'][$destination->locale]['written'] ?? []) !== [];
        $this->outcomes[] = [
            'target_type' => AiChangesetItem::TARGET_CONTENT,
            'target_id' => $destination->source->id,
            'outcome' => match (true) {
                $remaining !== [] => ReportsOutcomes::PARTIAL,
                $wrote => ReportsOutcomes::WRITTEN,
                default => ReportsOutcomes::SKIPPED,
            },
            'reason' => $remaining === [] && ! $wrote ? AiOperationItem::UNIT_EXCEPTIONS : null,
            'part' => $destination->locale,
            'units' => ['written' => $writtenUnits, 'remaining' => $remaining],
        ];

        return [
            ...$this->writeResult($write, $left),
            'action' => $destination->action(),
            'source_id' => $destination->source->id,
            'destination_id' => $write['written']->first()?->target_id ?? $translation?->id,
            'language' => $destination->locale,
            'units' => $writtenUnits,
            ...($remaining !== [] ? ['remaining_units' => $remaining] : []),
        ];
    }

    /**
     * What a batch may still write of a locale. A resumed batch gets the units an
     * answer reopened and nothing else, and a unit settled alone stays settled. A
     * unit that hit a conflict is not refused here: its field decides, by whether
     * the conflict is still open ({@see ProposalTool::leftForPerson()}).
     *
     * @param  array<string, mixed>  $texts
     *
     * @throws ToolError
     */
    private function ensureUnitsOwed(?OperationContext $operation, ?AiOperationItem $item, TranslationDestination $destination, array $texts): void
    {
        $locale = $destination->locale;
        $given = array_map('strval', array_keys($texts));

        $reopened = $item?->openUnits($locale) ?? [];
        if ($reopened !== []) {
            if (array_diff($given, $reopened) !== []) {
                throw new ToolError("Only these {$locale} units are owed in this batch: ".implode(', ', $reopened).'. Leave the others as they are.');
            }

            return;
        }

        $operation?->ensureNotSettled(AiChangesetItem::TARGET_CONTENT, $destination->source->id, $locale);
        $settled = array_intersect($given, $item?->settledUnits($locale, conflicts: false) ?? []);
        if ($settled !== []) {
            throw new ToolError(implode(', ', $settled).' settled on their own and stay as they are unless an editor answers.');
        }
    }

    /**
     * A run on a confirmed selection may translate the selected entries:
     * writing to a family member of a selected entry stays in scope. Any
     * other run counts entries against the inline limit.
     *
     * @throws ToolError
     */
    private function ensureTranslationInScope(TranslationDestination $destination): void
    {
        $frozen = $this->run->setup['targets'] ?? null;
        $family = array_values(array_filter([$destination->source->id, $destination->translation?->id]));

        if (\is_array($frozen)) {
            if ($frozen['type'] !== AiChangesetItem::TARGET_CONTENT || array_intersect($family, $frozen['ids']) === []) {
                throw new ToolError('This run works on the selected entries only. Translate those entries and nothing else.');
            }

            return;
        }

        $this->ensureInScope(AiChangesetItem::TARGET_CONTENT, $destination->translation?->id);
    }
}
