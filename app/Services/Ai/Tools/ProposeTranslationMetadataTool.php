<?php

namespace App\Services\Ai\Tools;

use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperationItem;
use App\Services\Ai\Changesets\ContentTarget;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Changesets\ProposalCopy;
use App\Services\Ai\Changesets\TargetSnapshot;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Metadata\MetadataOutputs;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Translation\TranslationDestination;
use App\Services\Ai\Translation\TranslationDestinations;
use App\Services\Ai\Translation\TranslationPlan;
use App\Services\Ai\Translation\TranslationProposer;

/**
 * Writes the metadata of a translation plan from that locale's draft, straight
 * to the draft. It runs after the translation is done: one change per meta field,
 * keeping every key it does not name, stored values unless the plan may overwrite
 * them. `version_id` pins the draft the model read, so text a person changed since
 * is read again first. A field with an open conflict stays open for a person.
 */
class ProposeTranslationMetadataTool extends ProposalTool implements ReportsOutcomes
{
    public const NAME = 'propose_translation_metadata';

    /** @var list<array{target_type: string, target_id: string, outcome: string, reason: string|null, part: string, note?: string}> */
    private array $outcomes = [];

    public function takeOutcomes(): array
    {
        [$outcomes, $this->outcomes] = [$this->outcomes, []];

        return $outcomes;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Writes page metadata for one translated locale of a source entry straight to its draft; nothing is published. Call it once the locale\'s translation is finished, with `draft.version_id` from get_translation_task. '
            .'Write every value in that language, from `draft.texts` only: not from the source language and not from older text. Title at most 60 characters, description at most 155. '
            .'`values` holds the owed keys per meta field path, such as {"seo": {"title": "..."}}; nested fields use their path from `metadata.owed`. Keys you do not name stay as they are. '
            .'The result holds the version_id for your next call. A field a person changed comes back under `conflicts`: it stays open for that person, so do not write it again. The rest is written. Report a key the text does not support with report_items `outputs` instead.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_id' => ['type' => 'string', 'description' => 'The source entry ID from get_translation_task.'],
                'language' => ['type' => 'string', 'description' => 'Target locale code.'],
                'version_id' => ['type' => 'string', 'description' => '`draft.version_id` of the text you wrote from.'],
                'values' => [
                    'type' => 'object',
                    'description' => 'Meta field path => {key: text}.',
                    'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                ],
            ],
            'required' => ['source_id', 'language', 'version_id', 'values'],
        ];
    }

    public function execute(array $input): mixed
    {
        $this->ensureCanWriteContent();
        $this->outcomes = [];

        $context = OperationContext::forRun($this->run);
        $plan = $context?->plan()['metadata'] ?? throw new ToolError('This run has no approved plan that generates metadata.');
        $values = \is_array($input['values'] ?? null) ? $input['values'] : [];
        if ($values === []) {
            throw new ToolError('Pass the owed keys under `values`.');
        }

        $approved = TranslationPlan::forRun($this->run);
        $destination = app(TranslationDestinations::class)->resolve($this->space, (string) ($input['source_id'] ?? ''), (string) ($input['language'] ?? ''), $approved->locales);
        $locale = $destination->locale;
        $item = $context->item(AiChangesetItem::TARGET_CONTENT, $destination->source->id);
        if (! $approved->generatesMetadata($locale)) {
            throw new ToolError("The approved plan generates no metadata for {$locale}.");
        }
        $row = $destination->translation;
        if ($row === null || ! $item->translationReady($locale)) {
            throw new ToolError("The {$locale} translation is not finished. Finish it or report what stops it; metadata is written from the finished translation.");
        }
        if (($input['version_id'] ?? null) !== $row->current_version_id) {
            throw new ToolError("The {$locale} draft is not what you read. Call get_translation_task again and write from its `draft`.");
        }

        $document = $row->getCurrentContent();
        [$patches, $report] = $this->patches($destination, $document, $item, $plan, $values);
        foreach ($report['kept'] as $part) {
            $this->report($destination, $part, self::SATISFIED, 'has_value');
        }
        foreach ($report['invalid'] as $part => $problem) {
            $this->report($destination, (string) $part, self::INVALID, 'invalid', $problem);
        }
        $left = $this->leftForPerson($row->id, array_map('strval', array_keys($patches)));
        $patches = array_diff_key($patches, $left);
        $isHeld = fn (string $part, array $paths) => array_filter($paths, fn (?string $path) => $path !== null && str_starts_with($part, "{$locale}.{$path}.")) !== [];

        if ($patches === []) {
            foreach ($report['written'] as $part) {
                $this->report($destination, $part, self::CONFLICT, null);
            }
            $report['written'] = [];
            $report['owed'] = array_values(array_diff($item->openParts(null), $report['kept']));

            return [
                ...$this->writeResult(['written' => collect(), 'conflicts' => collect(), 'version_id' => $row->current_version_id], $left),
                'metadata' => $report,
            ];
        }

        $snapshot = new TargetSnapshot($row, (string) $row->name, $document, $row->current_version_id);
        $types = app(ContentTarget::class)->validate($this->space, $snapshot, $patches);
        $derived = $context->derivedFrom(AiChangesetItem::TARGET_CONTENT, $destination->source->id);
        $paths = array_map('strval', array_keys($patches));
        $write = $this->writeContent(function () use ($destination, $snapshot, $paths, $patches, $types, $derived) {
            $items = $this->record(array_map(fn (string $path) => [
                'operation' => AiChangesetItem::OPERATION_UPDATE,
                'target_type' => AiChangesetItem::TARGET_CONTENT,
                'target_id' => $snapshot->model->getKey(),
                'target_label' => $snapshot->label,
                'base_version_id' => $snapshot->versionId,
                'path' => $path,
                'field_type' => $types[$path] ?? null,
                'before' => FieldPath::get($snapshot->document, $path),
                'after' => $patches[$path],
                ...$derived,
            ], $paths));

            foreach ($items as $recorded) {
                $this->review($destination, $recorded);
            }

            return $items;
        });

        $conflicted = [...$write['conflicts']->pluck('path')->all(), ...array_map('strval', array_keys($left))];
        foreach ($report['written'] as $part) {
            $this->report($destination, $part, $isHeld($part, $conflicted) ? self::CONFLICT : self::WRITTEN, null);
        }
        $report['written'] = array_values(array_filter($report['written'], fn (string $part) => ! $isHeld($part, $conflicted)));
        $report['owed'] = array_values(array_diff($item->openParts(null), $report['written'], $report['kept']));

        return [...$this->writeResult($write, $left), 'metadata' => $report];
    }

    /**
     * Merge what was generated over the field, key by key, under the plan.
     *
     * @param  array<string, mixed>  $document  the draft
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, mixed>, 1: array{written: list<string>, kept: list<string>, invalid: array<string, string>, owed: list<string>}}
     */
    private function patches(TranslationDestination $destination, array $document, AiOperationItem $item, array $plan, array $values): array
    {
        $locale = $destination->locale;
        $expected = (array) ($item->detail['expected_parts'] ?? []);
        $report = ['written' => [], 'kept' => [], 'invalid' => [], 'owed' => []];
        $patches = [];

        foreach ($values as $path => $generated) {
            $path = (string) $path;
            $owed = array_filter($expected, fn (string $part) => str_starts_with($part, "{$locale}.{$path}.") && ! str_contains(substr($part, \strlen("{$locale}.{$path}.")), '.'));
            if ($owed === []) {
                $report['invalid']["{$locale}.{$path}"] = "{$path} is no metadata this entry owes in {$locale}.";

                continue;
            }

            if (\is_array($generated)) {
                foreach (array_keys($generated) as $key) {
                    if ($item->settledEarlier("{$locale}.{$path}.{$key}")) {
                        unset($generated[$key]);
                        if ($item->deliveredEarlier("{$locale}.{$path}.{$key}")) {
                            $report['kept'][] = "{$locale}.{$path}.{$key}";
                        }
                    }
                }
            }

            $stored = FieldPath::get($document, $path);
            $base = \is_array($stored) && $stored !== [] ? $stored : app(TranslationProposer::class)->blankedComposite(FieldPath::get($destination->source->getCurrentContent(), $path), 'meta');
            $result = MetadataOutputs::apply(\is_array($stored) ? $stored : [], $base, $generated, $plan['keys'], $plan['overwrite_keys'] ?? []);

            foreach ($result['written'] as $key) {
                $report['written'][] = "{$locale}.{$path}.{$key}";
            }
            foreach ($result['kept'] as $key) {
                $report['kept'][] = "{$locale}.{$path}.{$key}";
            }
            foreach ($result['invalid'] as $key => $problem) {
                $report['invalid'][$key === '*' ? "{$locale}.{$path}" : "{$locale}.{$path}.{$key}"] = "{$path}.{$key} {$problem}";
            }
            if ($result['written'] !== []) {
                $patches[$path] = $result['value'];
            }
        }

        return [$patches, $report];
    }

    /**
     * The checks a change gets under the pinned policy, in the locale it is
     * really written in. Generated text need not hold every term of the page,
     * so its findings are wording it must not use.
     */
    private function review(TranslationDestination $destination, AiChangesetItem $item): void
    {
        $policy = app(KnowledgeResolver::class)->policy($this->space, $this->run);
        $output = ProposalCopy::text($item->after);
        $found = app(GlossaryChecker::class)->check('', $output, $destination->sourceLocale, $destination->locale, $policy->concepts);
        $findings = array_values(array_unique([...($item->glossary_findings ?? []), ...GlossaryChecker::blocking($found)], SORT_REGULAR));
        $this->addHints([$item->id => GlossaryChecker::hints($found)]);

        $item->update([
            'glossary_findings' => $findings ?: null,
            ...($policy->isPinned() ? ['review' => [
                ...($item->review ?? []),
                'locales' => ['source' => $destination->locale, 'target' => $destination->locale],
            ]] : []),
        ]);
    }

    private function report(TranslationDestination $destination, string $part, string $outcome, ?string $reason, ?string $note = null): void
    {
        $this->outcomes[] = array_filter(
            ['target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $destination->source->id, 'outcome' => $outcome, 'part' => $part, 'note' => $note],
            fn ($value) => $value !== null,
        ) + ['reason' => $reason];
    }
}
