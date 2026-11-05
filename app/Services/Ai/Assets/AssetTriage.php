<?php

namespace App\Services\Ai\Assets;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationItemStatus;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\Asset;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Operations\OperationTriage;
use Illuminate\Support\Collection;

/**
 * Settles assets before a batch reaches the model, so their images are
 * never sent: an asset with proposals waiting for review in another
 * changeset is skipped (`pending_review`), and under a plan that only fills
 * empty metadata and adds tags, an asset that is already tagged and has every
 * AI-writable field filled in every language the plan writes is unchanged
 * (`complete`). Planned folders or tags, overwrites, removals and moves
 * leave every asset to the model.
 *
 * A plan naming `outputs` freezes what each asset owes instead: one part per
 * language and requested field, `tags` when requested and `folder` when the
 * plan may move. Stored values settle their parts here, and the asset goes to
 * the model for what is left. A field its folder does not let AI write is a
 * visible skip. A stored value the plan may replace stays open as `may_keep`.
 */
class AssetTriage implements OperationTriage
{
    public function __construct(private readonly AssetFieldRules $rules) {}

    public function triage(AiOperation $operation, Collection $items): array
    {
        $ids = $items->pluck('target_id')->all();
        $waiting = AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_ASSET)
            ->whereIn('target_id', $ids)
            ->whereIn('status', [AiChangesetItemStatus::Pending->value, AiChangesetItemStatus::Conflict->value])
            ->whereHas('changeset', fn ($changeset) => $changeset->where(fn ($other) => $other->whereNull('operation_id')->orWhere('operation_id', '!=', $operation->id)))
            ->distinct()
            ->pluck('target_id')
            ->flip();

        $approved = (array) $operation->approved_plan;
        $plan = AssetPlan::forOperation($approved, []);
        $fillsOnly = $plan->overwrite === [] && ! $plan->move && ! $plan->removeTags
            && ($approved['structure']['folders'] ?? []) === [] && ($approved['structure']['tags'] ?? []) === [];
        $assets = $fillsOnly ? Asset::query()->with('folder')->whereIn('id', $ids)->get()->keyBy('id') : collect();
        $languages = $plan->languages ?? $this->rules->languages($operation->space);
        $outputs = \is_array($approved['outputs'] ?? null) ? $approved['outputs'] : null;
        $assets = $outputs !== null ? Asset::query()->with('folder')->whereIn('id', $ids)->get()->keyBy('id') : $assets;
        $decided = [];

        foreach ($items as $item) {
            $asset = $assets->get($item->target_id);
            $decided[$item->id] = match (true) {
                isset($waiting[$item->target_id]) => ['status' => AiOperationItemStatus::Skipped, 'reason' => 'pending_review'],
                $outputs !== null && $asset instanceof Asset => $this->owed($operation, $asset, $plan, $outputs, $languages, $fillsOnly),
                $outputs !== null => null,
                $asset instanceof Asset && $this->complete($operation, $asset, $languages) => ['status' => AiOperationItemStatus::Unchanged, 'reason' => 'complete'],
                default => null,
            };
        }

        return array_filter($decided);
    }

    /**
     * @param  array{fields: list<string>, tags: bool}  $outputs
     * @param  list<string>  $languages
     * @return array{expected: list<string>, overwritable: list<string>, parts: array<string, array{outcome: string, reason: string}>}
     */
    private function owed(AiOperation $operation, Asset $asset, AssetPlan $plan, array $outputs, array $languages, bool $fillsOnly): array
    {
        $fields = (array) data_get($asset->data, 'fields', []);
        $writable = $this->rules->keys($operation->space, $asset->folder);
        $expected = [];
        $overwritable = [];
        $parts = [];

        foreach ($languages as $language) {
            foreach ($outputs['fields'] as $field) {
                $part = "{$language}.{$field}";
                $expected[] = $part;

                if (! \in_array($field, $writable, true)) {
                    $parts[$part] = ['outcome' => ReportsOutcomes::SKIPPED, 'reason' => 'field_not_available'];
                } elseif (! AssetFieldRules::isEmpty($fields[$language][$field] ?? null)) {
                    if ($plan->overwrites($field)) {
                        $overwritable[] = $part;
                    } else {
                        $parts[$part] = ['outcome' => ReportsOutcomes::SATISFIED, 'reason' => 'has_value'];
                    }
                }
            }
        }

        if ($outputs['tags']) {
            $expected[] = AssetPlan::PART_TAGS;
            if (array_filter((array) ($asset->tags ?? []), 'is_string') !== []) {
                if ($fillsOnly) {
                    $parts[AssetPlan::PART_TAGS] = ['outcome' => ReportsOutcomes::SATISFIED, 'reason' => 'has_tags'];
                } else {
                    $overwritable[] = AssetPlan::PART_TAGS;
                }
            }
        }

        if ($plan->move) {
            $expected[] = AssetPlan::PART_FOLDER;
            $overwritable[] = AssetPlan::PART_FOLDER;
        }

        return ['expected' => $expected, 'overwritable' => $overwritable, 'parts' => $parts];
    }

    /** @param  list<string>  $languages */
    private function complete(AiOperation $operation, Asset $asset, array $languages): bool
    {
        if (array_filter((array) ($asset->tags ?? []), 'is_string') === []) {
            return false;
        }

        $fields = (array) data_get($asset->data, 'fields', []);
        foreach ($languages as $language) {
            foreach ($this->rules->keys($operation->space, $asset->folder) as $key) {
                if (AssetFieldRules::isEmpty($fields[$language][$key] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }
}
