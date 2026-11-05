<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiOperationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiOperationItem
 *
 * @resourceProperty position type=integer
 * @resourceProperty group shape=string?
 * @resourceProperty status shape=enum:pending|running|written|proposed|conflict|unchanged|skipped|failed
 * @resourceProperty reason shape=string?
 * @resourceProperty note shape=string? The run's own words for an `agent` reason.
 * @resourceProperty version_changed type=boolean
 * @resourceProperty parts shape=map:@AiOperationPartOutcome Outcome per output: `<locale>` or `<locale>#<unit id>` for translation, `<language>.<field>`, `tags`, `folder` for assets, `<locale>.<stable field path>.<key>` for metadata.
 * @resourceProperty expected_parts shape=string[]? Outputs this entry owes, frozen from the approved plan. Null when it is one piece.
 * @resourceProperty overwritable shape=string[] Open outputs holding a value the plan may replace.
 * @resourceProperty units shape=map:@AiOperationItemUnits Translation units by locale.
 * @resourceProperty attempts type=integer
 * @resourceProperty batch shape=integer?
 * @resourceProperty run_id shape=string?
 * @resourceProperty proposals shape=integer Open and decided proposals of this entry, on the ledger list.
 */
class AiOperationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'label' => $this->label,
            'group' => $this->group,
            'status' => $this->status->value,
            'reason' => $this->reason,
            // The run's own words for an `agent` reason.
            'note' => $this->detail['note'] ?? null,
            'version_changed' => (bool) ($this->detail['version_changed'] ?? false),
            // Outcomes by locale or requested output path.
            'parts' => (object) ($this->detail['parts'] ?? []),
            'expected_parts' => $this->detail['expected_parts'] ?? null,
            'overwritable' => $this->detail['overwritable'] ?? [],
            'units' => (object) ($this->detail['units'] ?? []),
            'attempts' => $this->attempts,
            'batch' => $this->batch,
            'run_id' => $this->run_id,
            'proposals' => $this->whenCounted('proposals'),
        ];
    }
}
