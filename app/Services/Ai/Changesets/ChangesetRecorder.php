<?php

namespace App\Services\Ai\Changesets;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\Space\Content;
use App\Models\Space\DataEntry;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Ai\Tools\ProposalTool;
use App\Services\Asset\AssetMetadataFieldResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Records a run's changes into its changeset: one per run, or one per operation
 * shared by all its batch runs. In a batch every item links the ledger entry it
 * accounts for. A proposal for an entry that is one piece marks it `proposed` at
 * once, so a crash after the proposal never queues the entry again. Content tools
 * write to the draft right after recording and report the outcome themselves. An
 * entry worked in parts is never done by one change: its tool reports each part
 * it covers, and a crash repeats the call. A derived change (a locale entry, a
 * translation row, a folder) must carry `operation_item_id` from
 * OperationContext::derivedFrom() for the same reason.
 */
class ChangesetRecorder
{
    /** @var array<string, list<array<string, string>>> item ID => glossary hints, for the agent only */
    private array $hints = [];

    public function __construct(
        private readonly GlossaryChecker $checker,
        private readonly AiThreads $threads,
        private readonly KnowledgeResolver $knowledge,
    ) {}

    /**
     * The hints the last {@see record()} found, by item ID. A hint is told to the
     * agent and never stored, so no reviewer sees it.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function takeHints(): array
    {
        [$hints, $this->hints] = [$this->hints, []];

        return $hints;
    }

    /**
     * Store items, replacing a still pending change of the same field so the model
     * can correct itself. A conflict stays: it is a person's to decide, and the
     * write tools refuse the field until then ({@see ProposalTool::leftForPerson()}).
     *
     * @param  list<array<string, mixed>>  $items  item attributes without changeset; status defaults to pending
     * @return list<AiChangesetItem>
     *
     * @throws ToolError when a batch change names no entry of its batch
     */
    public function record(AiRun $run, array $items, bool $inline = false): array
    {
        $this->hints = [];
        $direct = [];
        if ($run->operation_id !== null && ($run->setup['operation']['phase'] ?? null) === OperationContext::PHASE_BATCH) {
            $operationParts = AiOperation::query()->find($run->operation_id)?->expectedParts();
            foreach ($items as $index => $attributes) {
                if (isset($attributes['operation_item_id'])) {
                    continue;
                }

                $entry = ($attributes['target_id'] ?? null) === null ? null : AiOperationItem::query()
                    ->where('operation_id', $run->operation_id)
                    ->where('run_id', $run->id)
                    ->where('target_type', $attributes['target_type'])
                    ->where('target_id', $attributes['target_id'])
                    ->first(['id', 'detail']);
                if ($entry === null) {
                    throw new ToolError('This change is not on an entry of this batch. Change the entries under `targets`, or derive new entries from them with the tools made for it.');
                }

                $items[$index]['operation_item_id'] = (int) $entry->id;
                if ($attributes['target_type'] !== AiChangesetItem::TARGET_CONTENT && $entry->expectedParts($operationParts) === null) {
                    $direct[$index] = (int) $entry->id;
                }
            }
        }

        $changeset = AiChangeset::query()->firstOrCreate($run->operation_id !== null ? ['operation_id' => $run->operation_id] : ['run_id' => $run->id], [
            'run_id' => $run->id,
            'space_id' => $run->space_id,
            'actor_id' => $run->actor_id,
            'title' => $this->title($run),
            'inline' => $inline,
        ]);
        // An operation's planned structure opens its changeset before the first batch run.
        if ($changeset->run_id === null) {
            $changeset->forceFill(['run_id' => $run->id])->save();
        }

        $policy = isset($run->setup['policy']['id']) ? $this->knowledge->policy($run->space, $run) : null;
        $created = [];
        foreach ($items as $index => $attributes) {
            $language = $inline || $changeset->inline ? null : $this->writtenIn($run, $attributes);
            [$sourceLocale, $targetLocale] = $this->checkedIn($run, $language);
            if ($policy !== null) {
                $source = implode("\n", array_filter(Arr::flatten(Arr::wrap($attributes['before'] ?? $attributes['after'] ?? null)), 'is_string'));
                $activated = $policy->activate($source, $sourceLocale, $targetLocale);
                $attributes['review'] = [
                    ...($attributes['review'] ?? []),
                    'policy' => $policy->provenance(array_column($activated, 'concept')),
                    ...($language !== null ? ['locales' => ['source' => $language, 'target' => $language]] : []),
                ];
            }
            if (($attributes['path'] ?? null) !== null) {
                $changeset->items()
                    ->where('status', AiChangesetItemStatus::Pending->value)
                    ->where('target_type', $attributes['target_type'])
                    ->where('target_id', $attributes['target_id'])
                    ->where('path', $attributes['path'])
                    ->delete();
            }

            $findings = $this->findings($run, $policy, (string) $sourceLocale, (string) $targetLocale, $attributes['before'] ?? null, $attributes['after'] ?? null);
            $item = $changeset->items()->create([
                'status' => AiChangesetItemStatus::Pending,
                ...$attributes,
                // Domain review flags keep the item out of group accepts like any other flag.
                'flags' => ($attributes['flags'] ?? null) ?: (($attributes['review']['flags'] ?? null) ?: null),
                'glossary_findings' => GlossaryChecker::blocking($findings) ?: null,
            ]);
            if (($hints = GlossaryChecker::hints($findings)) !== []) {
                $this->hints[$item->id] = $hints;
            }
            $created[] = $item;

            if (isset($direct[$index])) {
                AiOperationItem::markProposed([$direct[$index]]);
            }
        }

        $changeset->refreshCounts();

        // Only a background changeset can put its thread up for a person.
        if (! $changeset->inline) {
            $this->threads->changed($run->space_id, $run->conversation_id, activity: false);
        }

        return $created;
    }

    /**
     * Distinct entries this run already changed, created or proposed changes to.
     *
     * @return list<string> "type:id"
     */
    public function touchedTargets(AiRun $run): array
    {
        return AiChangesetItem::query()
            ->whereHas('changeset', fn ($query) => $query->where('run_id', $run->id))
            ->get(['id', 'target_type', 'target_id'])
            // A new entry not written yet counts as an entry of its own.
            ->map(fn (AiChangesetItem $item) => "{$item->target_type}:".($item->target_id ?? "new-{$item->id}"))
            ->unique()
            ->values()
            ->all();
    }

    private function title(AiRun $run): string
    {
        return Str::limit((string) (($run->setup['title'] ?? '') ?: $run->skill ?: $run->surface), 180);
    }

    /**
     * The locales a change is checked in: the run's, when it names a translation or the
     * change has no language of its own, else the language the change is written in.
     *
     * @param  string|null  $language  from {@see writtenIn()}
     * @return array{0: string|null, 1: string|null}
     */
    private function checkedIn(AiRun $run, ?string $language): array
    {
        $source = $run->setup['glossary']['source_locale'] ?? null;
        $target = $run->setup['glossary']['target_locale'] ?? null;

        return $language === null || GlossaryConcept::translating((string) $source, (string) $target) ? [$source, $target] : [$language, $language];
    }

    /**
     * Only a translation checks an empty field against what the run read. Any other
     * write has nothing to keep there and answers for its own wording alone: it need
     * not hold every term of the page or the batch.
     *
     * @return list<array<string, string>>
     */
    private function findings(AiRun $run, ?PolicySnapshot $policy, string $sourceLocale, string $targetLocale, mixed $before, mixed $after): array
    {
        $glossary = $run->setup['glossary'] ?? null;
        $output = implode("\n", array_filter(Arr::flatten(Arr::wrap($after)), 'is_string'));

        if (! $glossary || $output === '') {
            return [];
        }

        $terms = $policy?->concepts ?? GlossaryConcept::listFromFrozen($glossary['concepts'] ?? $glossary['terms'] ?? []);
        $existing = implode("\n", array_filter(Arr::flatten(Arr::wrap($before)), 'is_string'));
        $source = $existing !== '' || ! GlossaryConcept::translating($sourceLocale, $targetLocale) ? $existing : (string) $glossary['source'];

        return $this->checker->check($source, $output, $sourceLocale, $targetLocale, $terms);
    }

    /**
     * The language a change's copy is written in, as the destination stores it and
     * never read from the text. Null for values without copy, which keep the run's
     * pair and record none, so an edit of them is not checked. A data source without
     * translatable dimensions names no language, so its entries read the run's.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writtenIn(AiRun $run, array $attributes): ?string
    {
        $default = $run->space->settings->getDefaultLanguage();
        $path = (string) ($attributes['path'] ?? '');

        return match ((string) $attributes['target_type']) {
            AiChangesetItem::TARGET_CONTENT => ($attributes['operation'] ?? null) === AiChangesetItem::OPERATION_CREATE
                ? (((array) ($attributes['after'] ?? []))['language_iso'] ?? $default)
                : (Content::query()->whereKey($attributes['target_id'] ?? null)->value('language_iso') ?: $default),
            AiChangesetItem::TARGET_ASSET => str_starts_with($path, 'fields.')
                ? (($key = explode('.', $path)[1]) === AssetMetadataFieldResolver::DEFAULT_LANGUAGE_KEY ? $default : $key)
                : null,
            AiChangesetItem::TARGET_DATA_ENTRY => $this->dataEntryLanguage((string) ($attributes['target_id'] ?? ''), $path) ?? ($run->setup['glossary']['target_locale'] ?? null),
            default => null,
        };
    }

    /** A translatable data source keeps a language per dimension, and `value` in its default one. */
    private function dataEntryLanguage(string $id, string $path): ?string
    {
        $settings = DataEntry::query()->with('dataSource')->find($id)?->dataSource?->settings;

        if (! ($settings['dimensions_translatable'] ?? false)) {
            return null;
        }

        return str_starts_with($path, 'dimensions.') ? substr($path, 11) : (($settings['default_dimension_locale'] ?? null) ?: null);
    }
}
