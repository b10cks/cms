<?php

namespace App\Models\Space;

use App\Enums\AiOperationItemStatus;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One frozen entry of an operation and its outcome: `written` once its change is
 * in the draft, `conflict` when a person changed what the agent read and nothing
 * was written, `proposed` once a proposal for a target without a draft layer
 * accounts for it, `unchanged` or `skipped` (with a reason) when the run or
 * deterministic triage says so, `failed` after the configured number of batches
 * left it unaccounted for.
 *
 * An entry worked in parts keeps one outcome per part in `detail.parts`. The
 * parts are the operation's (the locales of a translation) or frozen on the
 * entry in `detail.expected_parts` from the approved plan and the real field
 * rules: `<language>.<field>`, `tags` and `folder` for an asset,
 * `<locale>.<meta field path>.<key>` for page metadata, on a translation too
 * when its plan generates metadata. `detail.overwritable` names open parts that
 * hold a value the plan allows replacing, and `detail.units` the units written
 * and remaining per locale of a translation.
 *
 * A translation unit that cannot be done alone becomes its own part,
 * `<locale>#<unit id>`, listed in `detail.unit_parts`. It keeps its outcome and
 * question without erasing the locale's other units, and an answer resumes just
 * that unit.
 *
 * A part blocked on a missing fact keeps its question until an editor answers
 * it. `detail.answers[<part>]` holds every answer given for it, oldest first:
 * `{id, question, answer, answered_by, answered_at, fingerprint}`. An answer is
 * context for the next batch of that part and nothing more.
 *
 * @property int $id
 * @property string $operation_id
 * @property int $position
 * @property string $target_type
 * @property string $target_id
 * @property string|null $label
 * @property string|null $group
 * @property string|null $base_version_id
 * @property AiOperationItemStatus $status
 * @property string|null $reason
 * @property array<string, mixed>|null $detail
 * @property int|null $batch
 * @property string|null $run_id
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiOperationItem extends SpaceDatabaseModel
{
    public const UNIT_SEPARATOR = '#';

    /** A locale whose every unit has its own outcome and none was proposed. */
    public const UNIT_EXCEPTIONS = 'unit_exceptions';

    /** A metadata key waiting for the translation it is written from. */
    public const AWAITING_TRANSLATION = 'awaiting_translation';

    /** Which part outcome decides the settled entry, strongest first. */
    private const SETTLE_ORDER = [
        ReportsOutcomes::WRITTEN,
        ReportsOutcomes::PROPOSED,
        ReportsOutcomes::CONFLICT,
        ReportsOutcomes::FAILED,
        ReportsOutcomes::UNFINISHED,
        ReportsOutcomes::SKIPPED,
        ReportsOutcomes::BLOCKED,
        ReportsOutcomes::UNINFERABLE,
        ReportsOutcomes::REFUSED,
        ReportsOutcomes::UNCHANGED,
        ReportsOutcomes::SATISFIED,
        ReportsOutcomes::INTENTIONALLY_EMPTY,
    ];

    protected $fillable = [
        'operation_id',
        'position',
        'target_type',
        'target_id',
        'label',
        'group',
        'base_version_id',
        'status',
        'reason',
        'detail',
        'batch',
        'run_id',
        'attempts',
    ];

    protected $casts = [
        'status' => AiOperationItemStatus::class,
        'detail' => 'array',
        'position' => 'integer',
        'batch' => 'integer',
        'attempts' => 'integer',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(AiOperation::class, 'operation_id');
    }

    /** Changeset items that account for this entry. */
    public function proposals(): HasMany
    {
        return $this->hasMany(AiChangesetItem::class, 'operation_item_id');
    }

    /**
     * The parts this entry owes, or null when it is one piece.
     *
     * @param  list<string>|null  $operationParts  {@see AiOperation::expectedParts()}
     * @return list<string>|null
     */
    public function expectedParts(?array $operationParts): ?array
    {
        $parts = $this->detail['expected_parts'] ?? $operationParts;
        $units = $this->detail['unit_parts'] ?? [];

        return $units !== [] ? [...($parts ?? []), ...$units] : $parts;
    }

    /** The part of one translation unit of a locale. */
    public static function unitPart(string $locale, string $unit): string
    {
        return $locale.self::UNIT_SEPARATOR.$unit;
    }

    /** Make one translation unit answer for itself. */
    public function addUnitPart(string $part): void
    {
        $detail = $this->detail ?? [];
        $detail['unit_parts'] = array_values(array_unique([...($detail['unit_parts'] ?? []), $part]));

        $this->forceFill(['detail' => $detail])->save();
    }

    /**
     * Units of a locale settled by anything but a write: they no longer
     * hold the locale open, and only an answer lets them be written.
     *
     * @param  bool  $conflicts  whether a conflict counts: it holds the locale open no longer, but the agent may write it again
     * @return list<string>
     */
    public function settledUnits(string $locale, bool $conflicts = true): array
    {
        return $this->unitsOf($locale, fn (array $part) => ! self::isOpen($part)
            && ! \in_array($part['outcome'], ReportsOutcomes::DELIVERED, true)
            && ($conflicts || $part['outcome'] !== ReportsOutcomes::CONFLICT));
    }

    /**
     * Units of a locale reopened by an answer: all a resumed batch may touch of it.
     *
     * @return list<string>
     */
    public function openUnits(string $locale): array
    {
        return array_values(array_filter(
            array_map(fn (string $part) => substr($part, \strlen($locale) + 1), array_filter((array) ($this->detail['unit_parts'] ?? []), fn (string $part) => str_starts_with($part, $locale.self::UNIT_SEPARATOR))),
            fn (string $unit) => ! isset($this->detail['parts'][self::unitPart($locale, $unit)]) || self::isOpen($this->detail['parts'][self::unitPart($locale, $unit)]),
        ));
    }

    /**
     * Whether metadata can be written from this locale's draft: its work is done
     * or was not needed, and no unit of it waits for a fact. A conflict leaves a
     * person's text in the draft, and metadata reads that.
     */
    public function translationReady(string $locale): bool
    {
        $part = $this->detail['parts'][$locale] ?? null;
        $settled = \in_array($part['outcome'] ?? null, [...ReportsOutcomes::DELIVERED, ReportsOutcomes::CONFLICT, ReportsOutcomes::UNCHANGED, ReportsOutcomes::SATISFIED], true)
            || ($part['reason'] ?? null) === self::UNIT_EXCEPTIONS;

        return $settled
            && $this->openUnits($locale) === []
            && $this->unitsOf($locale, fn (array $unit) => $unit['outcome'] === ReportsOutcomes::BLOCKED) === [];
    }

    /**
     * Metadata of a locale can't be written until its translation can be: open
     * keys wait, visibly, and go back to owed with the answer that frees them.
     *
     * @param  list<string>|null  $operationParts
     */
    public function holdMetadata(string $locale, ?array $operationParts): void
    {
        foreach ($this->openParts($operationParts) as $part) {
            if (str_starts_with($part, "{$locale}.")) {
                $this->account(ReportsOutcomes::SKIPPED, self::AWAITING_TRANSLATION, $part, $operationParts);
            }
        }
    }

    /**
     * Expected parts without a settled outcome.
     *
     * @param  list<string>|null  $operationParts
     * @return list<string>
     */
    public function openParts(?array $operationParts): array
    {
        $parts = (array) ($this->detail['parts'] ?? []);

        return array_values(array_filter(
            $this->expectedParts($operationParts) ?? [],
            fn (string $name) => ! isset($parts[$name]) || self::isOpen($parts[$name]),
        ));
    }

    /**
     * Whether a part delivered by an earlier batch must stay as it is: a retry
     * owes the other parts only. The batch that delivered it may correct it.
     */
    public function deliveredEarlier(string $part): bool
    {
        $settled = $this->detail['parts'][$part] ?? null;

        return \in_array($settled['outcome'] ?? null, ReportsOutcomes::DELIVERED, true) && ($settled['batch'] ?? null) !== $this->batch;
    }

    /**
     * Whether an earlier batch settled a part this batch does not owe, a
     * blocked one above all: only an answer reopens it, so a batch working on
     * other parts leaves it alone. The batch that settled it may correct it.
     * Translation locales record no batch unless blocked and are not judged here.
     */
    public function settledEarlier(string $part): bool
    {
        $settled = $this->detail['parts'][$part] ?? null;
        if ($settled === null || self::isOpen($settled) || (! isset($settled['batch']) && ! isset($this->detail['expected_parts']))) {
            return false;
        }

        return ($settled['batch'] ?? null) !== $this->batch;
    }

    /**
     * Record an outcome ({@see ReportsOutcomes}) for the entry, or for one of
     * its parts when it works in parts. A delivered part keeps its outcome, and a
     * refused attempt never replaces a settled outcome. Once every expected
     * part is settled the entry settles too: written or proposed when anything
     * was, else conflict, failed, skipped or unchanged, in that order.
     *
     * Without `$part` the outcome is an entry-level claim, and a model's claim
     * alone never erases owed outputs: `unchanged` keeps only open parts the
     * plan merely allowed replacing, and a run's `skipped` keeps its note and
     * settles nothing. Only reasons from code (an unreadable file) cover the
     * parts still open.
     *
     * @param  list<string>|null  $expected  the operation's parts, null when entries are one piece
     */
    public function account(string $outcome, ?string $reason, ?string $part = null, ?array $expected = null, ?string $note = null, ?string $question = null): void
    {
        $detail = $this->detail ?? [];
        $expected = $this->expectedParts($expected);
        $note = $note !== null && $note !== '' ? mb_substr($note, 0, 300) : null;
        if ($note !== null && ($part === null || $expected === null || $expected === [])) {
            $detail['note'] = $note;
        }

        if ($expected === null || $expected === []) {
            $this->settle($outcome, $reason, $detail);

            return;
        }

        $parts = (array) ($detail['parts'] ?? []);
        foreach ($part !== null ? [$part] : $this->coveredBy($outcome, $reason, $expected) as $name) {
            $current = $parts[$name]['outcome'] ?? null;
            $keep = \in_array($current, ReportsOutcomes::DELIVERED, true) || ($outcome === ReportsOutcomes::INVALID && $current !== null);

            if (\in_array($name, $expected, true) && ! $keep) {
                $parts[$name] = array_filter([
                    'outcome' => $outcome,
                    'reason' => $reason,
                    'note' => $part !== null ? $note : null,
                    'question' => $question !== null ? mb_substr($question, 0, 500) : null,
                    // Frozen parts, units and any blocked one: other translation locales keep their stored shape.
                    'batch' => isset($detail['expected_parts']) || $outcome === ReportsOutcomes::BLOCKED || str_contains($name, self::UNIT_SEPARATOR) ? $this->batch : null,
                ], fn ($value) => $value !== null);
            }
        }
        $detail['parts'] = $parts;

        if (array_filter($expected, fn (string $name) => ! isset($parts[$name]) || self::isOpen($parts[$name])) !== []) {
            $this->forceFill(['detail' => $detail])->save();

            return;
        }

        $settled = collect($expected)->map(fn (string $name) => $parts[$name]);
        $final = collect(self::SETTLE_ORDER)->first(fn (string $candidate) => $settled->contains('outcome', $candidate)) ?? ReportsOutcomes::UNCHANGED;

        // Units written before the rest of a locale was given up on are in the draft all the same.
        $writtenUnits = collect((array) ($detail['units'] ?? []))->contains(fn (array $units) => ($units['written'] ?? []) !== []);
        if ($writtenUnits && $final !== ReportsOutcomes::WRITTEN) {
            $this->settle(ReportsOutcomes::WRITTEN, null, $detail);

            return;
        }

        $this->settle($final, $settled->firstWhere('outcome', $final)['reason'] ?? null, $detail);
    }

    /**
     * Keep which units of a locale were written and which remain, so partial
     * work shows field by field.
     *
     * @param  list<string>  $written
     * @param  list<string>  $remaining
     */
    public function recordUnits(string $part, array $written, array $remaining): void
    {
        $detail = $this->detail ?? [];
        $detail['units'][$part] = [
            'written' => array_values(array_unique([...($detail['units'][$part]['written'] ?? []), ...$written])),
            'remaining' => $remaining,
        ];

        $this->forceFill(['detail' => $detail])->save();
    }

    /**
     * Attempts ran out: what is still open ends as unfinished, or keeps the
     * invalid attempt it ended on. The caller sets the entry's own status.
     *
     * @param  list<string>|null  $operationParts
     */
    public function closeOpenParts(?array $operationParts, string $reason): void
    {
        $open = $this->openParts($operationParts);
        if ($open === []) {
            return;
        }

        $detail = $this->detail ?? [];
        foreach ($open as $name) {
            if (($detail['parts'][$name]['outcome'] ?? null) !== ReportsOutcomes::INVALID) {
                $detail['parts'][$name] = ['outcome' => ReportsOutcomes::UNFINISHED, 'reason' => $reason];
            }
        }

        $this->forceFill(['detail' => $detail])->save();
    }

    /**
     * Blocked parts with their questions, for the grouped question step.
     *
     * @return array<string, array{question: string|null, note: string|null}>
     */
    public function blockedParts(): array
    {
        return collect((array) ($this->detail['parts'] ?? []))
            ->filter(fn (array $part) => $part['outcome'] === ReportsOutcomes::BLOCKED)
            ->map(fn (array $part) => ['question' => $part['question'] ?? null, 'note' => $part['note'] ?? null])
            ->all();
    }

    /**
     * Owe blocked parts again once their question is answered. Everything else
     * stays settled, and the entry returns to the queue with fresh attempts.
     * An invalid part this entry already gave up on stays given up: an answer
     * pays for the parts it names and no others.
     *
     * @param  list<string>  $parts
     */
    public function reopenBlocked(array $parts): void
    {
        $detail = $this->detail ?? [];
        $reopened = array_intersect($parts, array_keys($this->blockedParts()));
        if ($reopened === []) {
            return;
        }

        foreach ($reopened as $name) {
            unset($detail['parts'][$name]);

            // An answered translation frees the metadata written from it. A metadata key's own answer frees nothing else.
            $locale = explode(self::UNIT_SEPARATOR, $name)[0];
            if (! str_contains($locale, '.')) {
                foreach ((array) ($detail['parts'] ?? []) as $other => $part) {
                    if (str_starts_with((string) $other, "{$locale}.") && ($part['reason'] ?? null) === self::AWAITING_TRANSLATION) {
                        unset($detail['parts'][$other]);
                    }
                }
            }
        }
        foreach ((array) ($detail['parts'] ?? []) as $name => $part) {
            if (self::isOpen($part)) {
                $detail['parts'][$name]['closed'] = true;
            }
        }

        $this->forceFill(['detail' => $detail, 'status' => AiOperationItemStatus::Pending, 'reason' => null, 'attempts' => 0, 'run_id' => null])->save();
    }

    /**
     * Keep an editor's answers and owe the parts they name again.
     *
     * @param  array<string, array{id: string, question: string|null, answer: string, answered_by: string, answered_at: string, fingerprint: string|null}>  $answers  by part
     */
    public function answerBlocked(array $answers): void
    {
        $detail = $this->detail ?? [];
        foreach ($answers as $part => $answer) {
            $detail['answers'][$part][] = $answer;
        }

        $this->detail = $detail;
        $this->reopenBlocked(array_map('strval', array_keys($answers)));
    }

    /**
     * What editors answered for the parts this batch owes, for its context.
     *
     * @param  list<string>|null  $operationParts
     * @return list<array{part: string, question: string|null, answer: string}>
     */
    public function answersForBatch(?array $operationParts): array
    {
        $answers = [];
        foreach ($this->openParts($operationParts) as $part) {
            foreach ((array) ($this->detail['answers'][$part] ?? []) as $answer) {
                $answers[] = ['part' => $part, 'question' => $answer['question'] ?? null, 'answer' => $answer['answer']];
            }
        }

        return $answers;
    }

    /**
     * Unit IDs of a locale whose part passes a test.
     *
     * @param  callable(array<string, mixed>): bool  $test
     * @return list<string>
     */
    private function unitsOf(string $locale, callable $test): array
    {
        $units = [];
        foreach ((array) ($this->detail['parts'] ?? []) as $name => $part) {
            if (str_starts_with((string) $name, $locale.self::UNIT_SEPARATOR) && $test($part)) {
                $units[] = substr((string) $name, \strlen($locale) + 1);
            }
        }

        return $units;
    }

    /**
     * Open parts an entry-level outcome may settle.
     *
     * @param  list<string>  $expected
     * @return list<string>
     */
    private function coveredBy(string $outcome, ?string $reason, array $expected): array
    {
        $open = $this->openParts($expected);

        return match (true) {
            $outcome === ReportsOutcomes::UNCHANGED => array_values(array_intersect($open, (array) ($this->detail['overwritable'] ?? []))),
            $outcome === ReportsOutcomes::SKIPPED && $reason !== ReportItemsTool::REASON, $outcome === ReportsOutcomes::FAILED => $open,
            default => [],
        };
    }

    /**
     * A part that is still owed although it has a record of an attempt.
     *
     * @param  array<string, mixed>  $part
     */
    private static function isOpen(array $part): bool
    {
        return $part['outcome'] === ReportsOutcomes::INVALID && ! ($part['closed'] ?? false);
    }

    /** @param  array<string, mixed>  $detail */
    private function settle(string $outcome, ?string $reason, array $detail): void
    {
        $status = match ($outcome) {
            ReportsOutcomes::WRITTEN => AiOperationItemStatus::Written,
            ReportsOutcomes::PROPOSED => AiOperationItemStatus::Proposed,
            ReportsOutcomes::CONFLICT => AiOperationItemStatus::Conflict,
            ReportsOutcomes::FAILED, ReportsOutcomes::UNFINISHED => AiOperationItemStatus::Failed,
            ReportsOutcomes::SKIPPED, ReportsOutcomes::BLOCKED, ReportsOutcomes::UNINFERABLE, ReportsOutcomes::REFUSED => AiOperationItemStatus::Skipped,
            default => AiOperationItemStatus::Unchanged,
        };
        $delivered = \in_array($status, [AiOperationItemStatus::Written, AiOperationItemStatus::Proposed], true);
        $applies = $delivered
            ? $this->status !== $status
            : \in_array($this->status->value, AiOperationItemStatus::owed(), true);

        $this->forceFill([
            'detail' => $detail,
            ...($applies ? ['status' => $status, 'reason' => $delivered ? null : ($reason !== null ? mb_substr($reason, 0, 60) : null)] : []),
        ])->save();
    }

    /**
     * Mark ledger items as accounted for by a proposal. Final outcomes other
     * than a proposal give way; a later proposal is the stronger signal.
     *
     * @param  list<int>  $ids
     */
    public static function markProposed(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        self::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->whereIn('status', [...AiOperationItemStatus::owed(), AiOperationItemStatus::Unchanged->value])
            ->update(['status' => AiOperationItemStatus::Proposed->value, 'reason' => null, 'updated_at' => now()]);
    }
}
