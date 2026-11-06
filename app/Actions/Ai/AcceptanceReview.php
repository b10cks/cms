<?php

namespace App\Actions\Ai;

use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\TranslationSource;
use App\Models\User;
use App\Services\Ai\Changesets\ProposalCopy;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What an accept must settle first, for the text it would apply. Runs
 * under the item locks of {@see DecidableItems}, so a rollback undoes it with
 * the decision.
 *
 * Content has a draft and a person publishes it, so its findings block nothing:
 * they stay on the item for whoever publishes. Other targets go live when
 * accepted, so a deterministic violation of their text needs correcting or a
 * reasoned override. Missing required wording is a hint: it asks for no reason
 * and records none. An edited text is checked again under the proposal's own
 * frozen policy: the old findings do not speak for it. A bulk accept leaves an
 * edit with a finding open instead of refusing.
 */
final class AcceptanceReview
{
    public function __construct(
        private readonly GlossaryChecker $checker,
        private readonly KnowledgeResolver $knowledge,
    ) {}

    /**
     * @param  Collection<int, AiChangesetItem>  $items  locked open items
     * @param  array<string, string>  $values
     * @param  array<string, list<array{finding_id: string, reason: string}>>  $overrides
     * @return array{ready: Collection<int, AiChangesetItem>, overrides: array<string, list<array<string, mixed>>>}
     *
     * @throws ValidationException outside a bulk accept, naming each finding as `overrides.<item>.<finding id>`, with nothing changed
     */
    public function check(Collection $items, array $values, array $overrides, User $user, bool $bulk = false): array
    {
        $missing = [];
        $left = [];
        $records = [];

        foreach ($items as $item) {
            // The open editor already applied a selection; accepting only records it.
            if ($item->target_type === AiChangesetItem::TARGET_SELECTION) {
                continue;
            }

            $edit = self::editOf($item, $values);
            if ($item->target_type === AiChangesetItem::TARGET_CONTENT) {
                if ($edit !== null) {
                    $item->glossary_findings = $this->recheck($item, $edit) ?: null;
                }

                continue;
            }

            $findings = $edit !== null ? $this->recheck($item, $edit) : ($item->glossary_findings ?? []);
            $given = array_column($overrides[$item->id] ?? [], 'reason', 'finding_id');
            $text = hash('sha256', $edit ?? ProposalCopy::text($item->after));

            foreach (GlossaryChecker::blocking($findings) as $finding) {
                $id = GlossaryChecker::findingId($finding);
                $reason = trim((string) ($given[$id] ?? ''));

                if ($reason === '') {
                    $left[$item->id] = true;
                    $missing["overrides.{$item->id}.{$id}"] = ["{$finding['type']}: {$finding['term']}".(isset($finding['expected']) ? " (expected {$finding['expected']})" : '').' needs a correction or an override with a reason.'];

                    continue;
                }

                $records[$item->id][] = [
                    'finding_id' => $id,
                    'finding' => $finding,
                    'reason' => $reason,
                    'text_sha256' => $text,
                    'policy_snapshot_id' => $item->review['policy']['snapshot_id'] ?? null,
                    'reviewer_id' => $user->id,
                    'overridden_at' => now()->toIso8601String(),
                ];
            }
        }

        if ($missing !== [] && ! $bulk) {
            throw ValidationException::withMessages($missing);
        }

        return ['ready' => $items->reject(fn (AiChangesetItem $item) => isset($left[$item->id]))->values(), 'overrides' => $records];
    }

    /**
     * Put an accepted item's overrides on it, to be saved with its decision.
     *
     * @param  array<string, list<array<string, mixed>>>  $records
     */
    public static function record(AiChangesetItem $item, array $records): void
    {
        if (isset($records[$item->id])) {
            $item->review = [...($item->review ?? []), 'overrides' => [...($item->review['overrides'] ?? []), ...$records[$item->id]]];
        }
    }

    /** The reviewer's text, when it differs from a text proposal. */
    public static function editOf(AiChangesetItem $item, array $values): ?string
    {
        return \is_string($item->after) && isset($values[$item->id]) && $values[$item->id] !== $item->after ? $values[$item->id] : null;
    }

    /**
     * Deterministic findings of an edit, under the terms its run worked with.
     *
     * @return list<array<string, string>>
     */
    private function recheck(AiChangesetItem $item, string $edit): array
    {
        $locales = $item->review['locales'] ?? [];
        $snapshot = $item->review['policy']['snapshot_id'] ?? null;
        $run = $snapshot === null ? AiRun::query()->whereKey($item->changeset()->value('run_id'))->first(['id', 'setup']) : null;
        $glossary = $run?->setup['glossary'] ?? [];
        $terms = $snapshot !== null
            ? $this->knowledge->snapshot($snapshot)->concepts
            : GlossaryConcept::listFromFrozen($glossary['concepts'] ?? $glossary['terms'] ?? []);
        $target = $locales['target'] ?? $glossary['target_locale'] ?? null;

        if ($terms === [] || $target === null) {
            return [];
        }

        return $this->checker->check($this->sourceOf($item), $edit, (string) ($locales['source'] ?? $glossary['source_locale'] ?? $target), (string) $target, $terms);
    }

    /** The text an edit translates: the lineage of a translation, or what the proposal replaces. */
    private function sourceOf(AiChangesetItem $item): string
    {
        $lineage = TranslationSource::query()->where('changeset_item_id', $item->id)->pluck('source_text')->filter(fn ($text) => \is_string($text) && $text !== '')->all();

        return $lineage !== [] ? implode("\n", $lineage) : ProposalCopy::text($item->before);
    }
}
