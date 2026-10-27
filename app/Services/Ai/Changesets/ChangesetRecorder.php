<?php

namespace App\Services\Ai\Changesets;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\AiChangeset;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\AiRun;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryTerm;
use App\Services\Ai\Threads\AiThreads;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** Writes a run's proposals into its changeset, one per run. */
class ChangesetRecorder
{
    public function __construct(
        private readonly GlossaryChecker $checker,
        private readonly AiThreads $threads,
    ) {}

    /**
     * Store items, replacing a still pending proposal for the same field so
     * the model can correct itself.
     *
     * @param  list<array<string, mixed>>  $items  item attributes without changeset; status defaults to pending
     * @return list<AiChangesetItem>
     */
    public function record(AiRun $run, array $items, bool $inline = false): array
    {
        $changeset = AiChangeset::query()->firstOrCreate(['run_id' => $run->id], [
            'space_id' => $run->space_id,
            'actor_id' => $run->actor_id,
            'title' => $this->title($run),
            'inline' => $inline,
        ]);

        $created = [];
        foreach ($items as $attributes) {
            if (($attributes['path'] ?? null) !== null) {
                $changeset->items()
                    ->where('status', AiChangesetItemStatus::Pending->value)
                    ->where('target_type', $attributes['target_type'])
                    ->where('target_id', $attributes['target_id'])
                    ->where('path', $attributes['path'])
                    ->delete();
            }

            $created[] = $changeset->items()->create([
                'status' => AiChangesetItemStatus::Pending,
                ...$attributes,
                'glossary_findings' => $this->findings($run, $attributes['before'] ?? null, $attributes['after'] ?? null) ?: null,
            ]);
        }

        $changeset->refreshCounts();

        // Only a background changeset puts its thread up for review.
        if (! $changeset->inline) {
            $this->threads->changed($run->space_id, $run->conversation_id, activity: false);
        }

        return $created;
    }

    /**
     * Distinct entries this run already proposed changes to or created.
     *
     * @return list<string> "type:id"
     */
    public function touchedTargets(AiRun $run): array
    {
        return AiChangesetItem::query()
            ->whereHas('changeset', fn ($query) => $query->where('run_id', $run->id))
            ->get(['id', 'target_type', 'target_id'])
            // A proposed new entry counts as an entry of its own.
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
     * @return list<array<string, string>>
     */
    private function findings(AiRun $run, mixed $before, mixed $after): array
    {
        $glossary = $run->setup['glossary'] ?? null;
        $output = implode("\n", array_filter(Arr::flatten(Arr::wrap($after)), 'is_string'));

        if (! $glossary || ($glossary['terms'] ?? []) === [] || $output === '') {
            return [];
        }

        $source = implode("\n", array_filter(Arr::flatten(Arr::wrap($before)), 'is_string')) ?: (string) $glossary['source'];

        return $this->checker->check(
            $source,
            $output,
            (string) $glossary['source_locale'],
            (string) $glossary['target_locale'],
            array_map(fn (array $term) => GlossaryTerm::fromArray($term), $glossary['terms']),
        );
    }
}
