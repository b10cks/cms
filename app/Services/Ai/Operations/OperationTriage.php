<?php

namespace App\Services\Ai\Operations;

use App\Enums\AiOperationItemStatus;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use Illuminate\Support\Collection;

/**
 * Deterministic outcomes before any model call, for entries the approved
 * plan leaves nothing to do on (no missing locale, no empty field). Register
 * one per kind in `ai.operations.triage`.
 *
 * An entry worked in parts (the locales of a translation) may instead get
 * outcomes for the parts code can settle; it goes to the model for the rest
 * and settles once every part has one (AiOperationItem::account()).
 *
 * `expected` freezes the parts an entry owes, the first time it gets here, from
 * the approved plan and the real field rules; later batches owe the same parts.
 * `overwritable` names open parts that hold a value the plan may replace.
 */
interface OperationTriage
{
    /**
     * @param  Collection<int, AiOperationItem>  $items  pending entries about to join a batch
     * @return array<int, array{status: AiOperationItemStatus, reason: string, detail?: array<string, mixed>}|array{parts?: array<string, array{outcome: string, reason: string|null}>, expected?: list<string>, overwritable?: list<string>}> by item ID; status Unchanged or Skipped only
     */
    public function triage(AiOperation $operation, Collection $items): array;
}
