<?php

namespace App\Services\Ai\Translation;

use App\Models\Management\Space;
use App\Services\Ai\Run\ToolError;

/**
 * Where each unit of one entry's translation into one locale stands, for the
 * translation view. Reads saved data and runs no AI: {@see TranslationWork}
 * decides, with every kind of work in the plan, so the view and a run agree.
 */
class TranslationStatus
{
    public function __construct(
        private readonly TranslationDestinations $destinations,
        private readonly TranslationWork $work,
    ) {}

    /**
     * Status per unit ID: `missing` (no translation entry yet), `empty`, `stale`
     * (with `previous_source`, the text it was translated from), `same_as_source`,
     * `current`, `inherited` (empty, a fallback locale fills it) or
     * `not_in_translation` (a standalone translation lacks the block). Units with
     * an empty source are left out.
     *
     * @return array{locale: string, source_locale: string, translation_id: string|null, units: array<string, array{status: string, previous_source?: string}>}
     *
     * @throws ToolError for an unknown locale or an entry with no source to translate from
     */
    public function of(Space $space, string $contentId, string $locale): array
    {
        $destination = $this->destinations->resolve($space, $contentId, $locale);
        $task = $this->work->tasks($space, [$destination], new TranslationPlan)[0];

        $units = array_map(fn (array $unit) => [
            'status' => $unit['reason'],
            ...(isset($unit['previous_source']) ? ['previous_source' => $unit['previous_source']] : []),
        ], $task->units);

        foreach ($task->held as $id => $reason) {
            $units[$id] = ['status' => $reason];
        }

        return [
            'locale' => $destination->locale,
            'source_locale' => $destination->sourceLocale,
            'translation_id' => $destination->translation?->id,
            'units' => $units,
        ];
    }
}
