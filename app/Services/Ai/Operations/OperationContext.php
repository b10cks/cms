<?php

namespace App\Services\Ai\Operations;

use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Run\ToolError;

/**
 * What a tool may do inside an operation run. Domain tools ask this instead
 * of trusting the model:
 *
 * - {@see item()} and {@see derivedFrom()}: a proposal accounts for an entry
 *   of this batch. A derived create (a new locale entry, a planned folder)
 *   carries `operation_item_id` from derivedFrom(), so it can only come from
 *   a frozen source entry the batch owns.
 * - {@see ensureLocale()}, {@see ensurePermission()}, {@see plannedFolder()},
 *   {@see isPlannedTag()}: the approved plan bounds every write.
 *
 * Planning runs propose no changes; every check here refuses them.
 */
final class OperationContext
{
    public const PHASE_PLAN = 'plan';

    public const PHASE_BATCH = 'batch';

    private function __construct(
        public readonly AiRun $run,
        public readonly AiOperation $operation,
        public readonly string $phase,
    ) {}

    /** The context of an operation run; null for every other run. */
    public static function forRun(AiRun $run): ?self
    {
        $phase = $run->setup['operation']['phase'] ?? null;
        if ($run->operation_id === null || ! \in_array($phase, [self::PHASE_PLAN, self::PHASE_BATCH], true)) {
            return null;
        }

        $operation = AiOperation::query()->find($run->operation_id);

        return $operation ? new self($run, $operation, $phase) : null;
    }

    public function isPlanning(): bool
    {
        return $this->phase === self::PHASE_PLAN;
    }

    /**
     * The approved plan a batch works to.
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $this->ensureBatch();

        return $this->operation->approved_plan ?? [];
    }

    /**
     * The ledger entry of a target this batch works on.
     *
     * @throws ToolError when the target is not an entry of this batch
     */
    public function item(string $type, string $id): AiOperationItem
    {
        $this->ensureBatch();

        return AiOperationItem::query()
            ->where('operation_id', $this->operation->id)
            ->where('run_id', $this->run->id)
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->first()
            ?? throw new ToolError('This entry is not part of this batch. Work on the entries listed under `targets` only.');
    }

    /**
     * The entry of this batch when it owes frozen per-output results; null for
     * any other run or entry. Outside a batch the tools' own checks apply.
     */
    public function owing(string $type, string $id): ?AiOperationItem
    {
        if ($this->isPlanning()) {
            return null;
        }

        $item = AiOperationItem::query()
            ->where('operation_id', $this->operation->id)
            ->where('run_id', $this->run->id)
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->first();

        return isset($item?->detail['expected_parts']) ? $item : null;
    }

    /**
     * Attributes for a proposal derived from an entry of this batch, such as
     * a new locale entry created from its source. Merge them into the item
     * attributes passed to ChangesetRecorder::record().
     *
     * @return array{operation_item_id: int}
     */
    public function derivedFrom(string $type, string $sourceId): array
    {
        return ['operation_item_id' => $this->item($type, $sourceId)->id];
    }

    /**
     * Account for outcomes tools reported ({@see ReportsOutcomes}). Entries
     * outside the batch are ignored: tools already refuse to work on them.
     *
     * @param  list<array{target_type: string, target_id: string, outcome: string, reason: string|null, part?: string, note?: string, units?: array{written: list<string>, remaining: list<string>}}>  $outcomes
     */
    public function account(array $outcomes): void
    {
        if ($this->isPlanning() || $outcomes === []) {
            return;
        }

        $items = AiOperationItem::query()
            ->where('operation_id', $this->operation->id)
            ->where('run_id', $this->run->id)
            ->whereIn('target_id', array_unique(array_column($outcomes, 'target_id')))
            ->get()
            ->keyBy(fn (AiOperationItem $item) => "{$item->target_type}:{$item->target_id}");
        $expected = $this->operation->expectedParts();

        foreach ($outcomes as $outcome) {
            $item = $items->get("{$outcome['target_type']}:{$outcome['target_id']}");
            if ($item === null) {
                continue;
            }

            if (isset($outcome['units'], $outcome['part'])) {
                $item->recordUnits($outcome['part'], $outcome['units']['written'], $outcome['units']['remaining']);
            }
            if ($outcome['outcome'] !== ReportsOutcomes::PARTIAL) {
                $item->account($outcome['outcome'], $outcome['reason'] ?? null, $outcome['part'] ?? null, $expected, $outcome['note'] ?? null);
            }
        }
    }

    /** @throws ToolError when the approved plan does not target this locale */
    public function ensureLocale(string $locale): void
    {
        if (! \in_array($locale, (array) ($this->plan()['locales'] ?? []), true)) {
            throw new ToolError("The approved plan does not include the locale {$locale}.");
        }
    }

    /** @throws ToolError when an earlier batch settled this part, say blocked it on a question nobody answered yet */
    public function ensureNotSettled(string $type, string $id, string $part): void
    {
        $item = AiOperationItem::query()
            ->where('operation_id', $this->operation->id)
            ->where('run_id', $this->run->id)
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->first();

        if ($item?->settledEarlier($part)) {
            throw new ToolError("{$part} is not owed in this batch: it is settled or waits for an editor's answer. Leave it as it is.");
        }
    }

    /** @throws ToolError when the approved plan does not grant this permission (overwrite, remove_tags, move) */
    public function ensurePermission(string $permission): void
    {
        if (($this->plan()['permissions'][$permission] ?? false) !== true) {
            throw new ToolError("The approved plan does not allow {$permission}. Leave it out or report the entry as skipped.");
        }
    }

    /**
     * A folder the approved plan introduced.
     *
     * @return array{key: string, name: string, parent_id: string|null, parent_key: string|null, reason: string, examples: list<string>}
     *
     * @throws ToolError when the plan has no such folder
     */
    public function plannedFolder(string $key): array
    {
        foreach ((array) ($this->plan()['structure']['folders'] ?? []) as $folder) {
            if (($folder['key'] ?? null) === $key) {
                return $folder;
            }
        }

        throw new ToolError("The approved plan has no folder {$key}.");
    }

    /** Whether the approved plan introduced this tag; existing tags are the domain's to check. */
    public function isPlannedTag(string $name): bool
    {
        $planned = array_map(fn (array $tag) => mb_strtolower($tag['name']), (array) ($this->plan()['structure']['tags'] ?? []));

        return \in_array(mb_strtolower(trim($name)), $planned, true);
    }

    private function ensureBatch(): void
    {
        if ($this->isPlanning()) {
            throw new ToolError('This run drafts the plan. Propose no changes; call propose_plan instead.');
        }
    }
}
