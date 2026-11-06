<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Knowledge\GlossaryBrief;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Translation\TranslationDestination;
use App\Services\Ai\Translation\TranslationDestinations;
use App\Services\Ai\Translation\TranslationMemory;
use App\Services\Ai\Translation\TranslationPlan;
use App\Services\Ai\Translation\TranslationTask;
use App\Services\Ai\Translation\TranslationTriage;
use App\Services\Ai\Translation\TranslationUnits;
use App\Services\Ai\Translation\TranslationWork;
use App\Services\Auth\AuthorizationService;

/**
 * Reads the translation work for one entry and locale: the units to
 * translate with their reason, the current translation and, for stale units,
 * the source text it was translated from. Matched glossary terms and translation
 * memory come with it, for the units of this read, all under the policy the run
 * pinned.
 *
 * A plan that generates metadata adds `draft`, the locale's text as it stands, and
 * `metadata` with what is owed and whether it can be written yet.
 */
class GetTranslationTaskTool implements AiToolInterface, ReportsOutcomes
{
    public const NAME = 'get_translation_task';

    private const PAGE = 100;

    private const MAX_GUIDE_CHARS = 8000;

    /** @var list<array{target_type: string, target_id: string, outcome: string, reason: string|null, part: string}> */
    private array $outcomes = [];

    public function __construct(
        /** Null outside a run (MCP): no approved plan, no selection. */
        private readonly ?AiRun $run,
        private readonly Space $space,
        private readonly User $actor,
        private readonly TranslationDestinations $destinations,
        private readonly TranslationWork $work,
        private readonly TranslationMemory $memory,
        private readonly KnowledgeResolver $knowledge,
        private readonly AuthorizationService $auth,
        private readonly TranslationUnits $units,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Reads what to translate for one entry (any language version of it) into one language. Returns the source and its version_id, the destination '
            .'(the translation entry and its version_id, or create when the language has none), and the units to translate: id, label, reason '
            .'(missing, empty, stale, same_as_source), source text, the current translation, and for stale units previous_source, the text the translation was made from. '
            .'Units with `memory` have an earlier translation of the same text that a person published and that passes the glossary; `memory_rejected` lists why an earlier one no longer does. '
            .'Also returns matching glossary terms and similar earlier translations as examples. '
            .'When the plan generates metadata, `draft` holds the locale\'s `texts` per unit and its `version_id`, and `metadata` lists what is `owed`, per field `path` and `key`, and whether it is `ready`.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'ID of the entry or of any of its translations.'],
                'language' => ['type' => 'string', 'description' => 'Target locale code.'],
                'offset' => ['type' => 'integer', 'description' => 'Skip this many units, to page through long entries.'],
            ],
            'required' => ['id', 'language'],
        ];
    }

    public function takeOutcomes(): array
    {
        [$outcomes, $this->outcomes] = [$this->outcomes, []];

        return $outcomes;
    }

    /** Locales code settles without a write count as done for their source. */
    private function reportSettled(TranslationTask $task): void
    {
        if ($settled = $task->settled()) {
            $this->outcomes[] = ['target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $task->destination->source->id, ...$settled, 'part' => $task->destination->locale];
        }
    }

    public function execute(array $input): mixed
    {
        $this->outcomes = [];
        if (! $this->auth->canInSpace($this->actor, $this->space, 'content.view')) {
            throw new ToolError('You do not have access to this information.');
        }

        $plan = $this->run ? TranslationPlan::forRun($this->run) : new TranslationPlan;
        $destination = $this->destinations->resolve($this->space, (string) ($input['id'] ?? ''), (string) ($input['language'] ?? ''), $plan->locales);
        [$task] = $this->work->tasks($this->space, [$destination], $plan, $this->run);
        $this->reportSettled($task);

        $offset = max(0, (int) ($input['offset'] ?? 0));
        $units = \array_slice(array_values($task->units), $offset, self::PAGE);
        $sources = array_column($units, 'source', 'id');
        $policy = $this->knowledge->policy($this->space, $this->run);
        $memory = $this->memory->exactUnderPolicy($sources, $destination->sourceLocale, $destination->locale, $policy->concepts);
        $source = $destination->source;
        $translation = $destination->translation;

        $hits = $policy->activate(implode("\n", $sources), $destination->sourceLocale, $destination->locale);
        $glossary = GlossaryBrief::render($hits, $destination->sourceLocale, $destination->locale, 'this entry');
        $bans = GlossaryBrief::bans($policy->concepts, $hits, $destination->locale);

        $inPrompt = $this->run?->setup['styles'] ?? [];
        $guides = array_filter($policy->styles([$destination->locale]), fn (array $guide) => $guide['locale'] !== null && ! isset($inPrompt[$guide['revision_id']]));

        return [
            'status' => $task->status(),
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'language' => $destination->sourceLocale,
                'block' => $source->block?->slug,
                'version_id' => $source->current_version_id,
            ],
            'destination' => [
                'action' => $destination->action(),
                'id' => $translation?->id,
                'name' => $translation?->name,
                'language' => $destination->locale,
                'version_id' => $translation?->current_version_id,
                'mode' => $destination->mode,
            ],
            'units' => array_map(fn (array $unit) => array_filter([
                'id' => $unit['id'],
                'label' => $unit['label'],
                'type' => $unit['type'],
                'reason' => $unit['reason'],
                'source' => $unit['source'],
                'previous_source' => $unit['previous_source'] ?? null,
                'current' => $unit['current'] !== '' ? $unit['current'] : null,
                'memory' => $memory['hits'][$unit['id']] ?? null,
                'memory_rejected' => $memory['rejected'][$unit['id']] ?? null,
            ], fn ($value) => $value !== null), $units),
            'more_units' => max(0, \count($task->units) - $offset - \count($units)),
            'kept' => array_count_values($task->held),
            ...($glossary !== null || $bans !== null ? ['glossary' => implode("\n\n", array_filter([$glossary, $bans]))] : []),
            // A chat turn's prompt holds only its own language's guide.
            ...($guides !== [] ? ['style_guides' => array_map(fn (array $guide) => [
                'title' => $guide['title'],
                'locale' => $guide['locale'],
                'revision_id' => $guide['revision_id'],
                'text' => mb_substr((string) ($guide['markdown'] ?? ''), 0, self::MAX_GUIDE_CHARS),
            ], array_values($guides))] : []),
            'examples' => $units === [] ? [] : $this->memory->fuzzy(array_values($sources), $destination->sourceLocale, $destination->locale, terms: $policy->concepts),
            ...$this->metadata($plan, $destination),
        ];
    }

    /**
     * The locale's draft, with the metadata owed from it.
     *
     * @return array<string, mixed>
     */
    private function metadata(TranslationPlan $plan, TranslationDestination $destination): array
    {
        if ($this->run === null || ! $plan->generatesMetadata($destination->locale)) {
            return [];
        }

        $row = $destination->translation;
        $document = $row?->getCurrentContent() ?? [];

        return [
            ...($row !== null ? ['draft' => [
                'id' => $row->id,
                'name' => $row->name,
                'version_id' => $row->current_version_id,
                'texts' => array_filter($this->units->texts($document, $destination->source->block?->schema?->toArray() ?? []), fn (string $text) => ! TranslationUnits::isBlank($text)),
            ]] : []),
            'metadata' => $this->owedMetadata($plan, $destination, $document),
        ];
    }

    /**
     * @param  array<string, mixed>  $document  the locale's draft
     * @return array{ready: bool, owed: list<array{part: string, path: string, key: string}>, keys: list<string>, overwrite_keys: list<string>}
     */
    private function owedMetadata(TranslationPlan $plan, TranslationDestination $destination, array $document): array
    {
        $item = OperationContext::forRun($this->run)?->owing(AiChangesetItem::TARGET_CONTENT, $destination->source->id);
        $locale = $destination->locale;
        $ready = $destination->translation !== null && $item?->translationReady($locale) === true;
        $owed = [];

        foreach ($item?->openParts(null) ?? [] as $part) {
            if (! str_starts_with($part, "{$locale}.")) {
                continue;
            }

            $path = substr($part, \strlen($locale) + 1, (int) strrpos($part, '.') - \strlen($locale) - 1);
            // A block the draft does not hold has no place for the field. Nothing can be written for it here.
            if ($ready && str_contains($path, '.') && ! \is_array(FieldPath::get($document, substr($path, 0, (int) strrpos($path, '.'))))) {
                $this->outcomes[] = ['target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $destination->source->id, 'outcome' => self::SKIPPED, 'reason' => TranslationTriage::NOT_IN_TRANSLATION, 'part' => $part];

                continue;
            }

            $owed[] = ['part' => $part, 'path' => $path, 'key' => substr($part, (int) strrpos($part, '.') + 1)];
        }

        return ['ready' => $ready, 'owed' => $owed, 'keys' => $plan->metadata['keys'], 'overwrite_keys' => $plan->metadata['overwrite_keys'] ?? []];
    }
}
