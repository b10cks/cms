<?php

namespace App\Services\Ai\Translation;

use App\Enums\AiOperationItemStatus;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Metadata\MetadataFields;
use App\Services\Ai\Metadata\MetadataTriage;
use App\Services\Ai\Operations\OperationTriage;
use Illuminate\Support\Collection;

/**
 * Settles translation work code can decide before a batch reaches the
 * model: per source and approved locale, a translation that is up to date, or
 * work the plan leaves out. A source with no destination (not in the default language, no block) is
 * skipped. Locales with work go to the model; the source settles once every
 * locale has an outcome.
 *
 * A plan that generates metadata freezes `<locale>.<field path>.<key>` for each
 * target locale too, from the source's meta fields. A key is settled here when
 * the row holds a value the plan keeps, or when no write can reach the field.
 */
class TranslationTriage implements OperationTriage
{
    /** The field isn't translatable, so an overlay translation can't hold it. */
    public const NOT_STORED = 'not_stored_on_translation';

    /** A standalone translation lacks the block holding the field; adding it is an editor's call. */
    public const NOT_IN_TRANSLATION = 'block_not_in_translation';

    /** The plan leaves out the work this locale needs, so there is no current text to write from. */
    public const NOT_TRANSLATED = 'translation_not_in_plan';

    public function __construct(
        private readonly TranslationDestinations $destinations,
        private readonly TranslationWork $work,
        private readonly MetadataFields $fields,
    ) {}

    public function triage(AiOperation $operation, Collection $items): array
    {
        $plan = TranslationPlan::fromArray(array_intersect_key($operation->approved_plan, array_flip(['locales', 'include', 'metadata'])));
        $byTarget = $items->keyBy('target_id');
        $resolved = $this->destinations->resolveMany($operation->space, $byTarget->keys()->all(), $plan->locales, $plan->locales);
        $decided = [];

        foreach ($resolved['skipped'] as ['id' => $id, 'reason' => $reason]) {
            if ($reason !== TranslationDestinations::SKIP_NOT_FOUND && $item = $byTarget->get($id)) {
                $decided[$item->id] = ['status' => AiOperationItemStatus::Skipped, 'reason' => $reason];
            }
        }

        // A row this operation created still owes the units it was created without.
        $tasks = $this->work->tasks($operation->space, $resolved['destinations'], $plan, (new AiRun)->forceFill(['operation_id' => $operation->id]));
        foreach ($tasks as $task) {
            $item = $byTarget->get($task->destination->source->id);
            if ($item instanceof AiOperationItem && $settled = $task->settled()) {
                $decided[$item->id]['parts'][$task->destination->locale] = $settled;
            }
        }

        return $plan->metadata !== null ? $this->metadata($plan, $byTarget, $tasks, $decided) : $decided;
    }

    /**
     * @param  Collection<string, AiOperationItem>  $items  by source
     * @param  list<TranslationTask>  $tasks
     * @param  array<int, array<string, mixed>>  $decided
     * @return array<int, array<string, mixed>>
     */
    private function metadata(TranslationPlan $plan, Collection $items, array $tasks, array $decided): array
    {
        foreach ($tasks as $task) {
            $destination = $task->destination;
            $item = $items->get($destination->source->id);
            if (! $item instanceof AiOperationItem) {
                continue;
            }

            $entry = $decided[$item->id] ?? [];
            $entry['expected'] ??= array_values((array) $plan->locales);
            $entry['overwritable'] ??= [];
            if ($plan->generatesMetadata($destination->locale)) {
                $this->decideLocale($task, $plan, $entry);
            }
            $decided[$item->id] = $entry;
        }

        return $decided;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function decideLocale(TranslationTask $task, TranslationPlan $plan, array &$entry): void
    {
        $destination = $task->destination;
        $source = $destination->source;
        $row = $destination->translation?->getCurrentContent();
        $status = $task->status();

        foreach ($this->fields->planned($plan->metadata, $source->getCurrentContent(), $source->block?->schema?->toArray() ?? []) as $field) {
            $path = $field['path'];
            $stored = $row !== null && $field['addressable'] ? FieldPath::get($row, $path) : null;
            $lacksBlock = $row !== null && ! $destination->overlay() && str_contains($path, '.') && ! \is_array(FieldPath::get($row, substr($path, 0, (int) strrpos($path, '.'))));

            foreach ($plan->metadata['keys'] as $key) {
                $part = "{$destination->locale}.{$path}.{$key}";
                $entry['expected'][] = $part;
                $filled = \is_array($stored) && \is_string($stored[$key] ?? null) && trim($stored[$key]) !== '';
                $reason = match (true) {
                    ! $field['addressable'] => MetadataTriage::NO_STABLE_PATH,
                    $destination->overlay() && ! $field['translatable'] => self::NOT_STORED,
                    $lacksBlock => self::NOT_IN_TRANSLATION,
                    $status === TranslationTask::STATUS_SKIPPED => self::NOT_TRANSLATED,
                    default => null,
                };

                if ($reason !== null) {
                    $entry['parts'][$part] = ['outcome' => ReportsOutcomes::SKIPPED, 'reason' => $reason];
                } elseif ($filled && \in_array($key, $plan->metadata['overwrite_keys'] ?? [], true)) {
                    $entry['overwritable'][] = $part;
                } elseif ($filled) {
                    $entry['parts'][$part] = ['outcome' => ReportsOutcomes::SATISFIED, 'reason' => 'has_value'];
                }
            }
        }
    }
}
