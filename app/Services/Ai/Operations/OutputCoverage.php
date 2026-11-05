<?php

namespace App\Services\Ai\Operations;

use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationKind;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Services\Ai\Contracts\ReportsOutcomes;

/**
 * What an operation's ledger says about the outputs it was asked for, stored
 * as `counts.coverage` when it finishes, pauses or is cancelled. A plan from
 * before output requirements, or one that names none, has no frozen outputs:
 * `verified` is false and `complete` null, so nobody reads it as field-complete.
 */
final class OutputCoverage
{
    private const FULFILLED = [...ReportsOutcomes::DELIVERED, ReportsOutcomes::SATISFIED, ReportsOutcomes::INTENTIONALLY_EMPTY];

    /**
     * @return array{verified: bool, complete: bool|null, outputs: array<string, int>, entries: array<string, int>}
     */
    public static function of(AiOperation $operation): array
    {
        $plan = (array) $operation->approved_plan;
        $verified = $operation->kind === AiOperationKind::Translation || isset($plan['outputs']) || isset($plan['metadata']);
        $expected = $operation->expectedParts();
        $outputs = [];
        $entries = [];
        $complete = true;

        AiOperationItem::query()->where('operation_id', $operation->id)->select(['id', 'status', 'detail'])->lazyById(500)->each(function (AiOperationItem $item) use ($expected, &$outputs, &$entries, &$complete) {
            $entries[$item->status->value] = ($entries[$item->status->value] ?? 0) + 1;
            $parts = $item->expectedParts($expected);
            // An entry without parts is settled as a whole, or was never reached.
            $complete = $complete && ($parts !== null ? $item->status !== AiOperationItemStatus::Pending : $item->status === AiOperationItemStatus::Unchanged);

            foreach ($parts ?? [] as $name) {
                $outcome = self::name($item->detail['parts'][$name]['outcome'] ?? ReportsOutcomes::UNFINISHED);
                $outputs[$outcome] = ($outputs[$outcome] ?? 0) + 1;
                $complete = $complete && \in_array($outcome, self::FULFILLED, true);
            }
        });

        return ['verified' => $verified, 'complete' => $verified ? $complete : null, 'outputs' => $outputs, 'entries' => $entries];
    }

    /**
     * Blocked outputs of the operation with their questions, flat and in full.
     * Editors get them grouped and paged from {@see OperationQuestions}.
     *
     * @return list<array{item_id: int, target_type: string, target_id: string, part: string, question: string|null, note: string|null}>
     */
    public static function blocked(AiOperation $operation): array
    {
        $blocked = [];

        AiOperationItem::query()->where('operation_id', $operation->id)->select(['id', 'target_type', 'target_id', 'detail'])->lazyById(500)->each(function (AiOperationItem $item) use (&$blocked) {
            foreach ($item->blockedParts() as $part => $open) {
                $blocked[] = ['item_id' => (int) $item->id, 'target_type' => $item->target_type, 'target_id' => $item->target_id, 'part' => (string) $part, ...$open];
            }
        });

        return $blocked;
    }

    /** Translation locales say `unchanged` for what other parts call satisfied. */
    private static function name(string $outcome): string
    {
        return $outcome === ReportsOutcomes::UNCHANGED ? ReportsOutcomes::SATISFIED : $outcome;
    }
}
