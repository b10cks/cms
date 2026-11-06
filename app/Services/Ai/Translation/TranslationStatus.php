<?php

namespace App\Services\Ai\Translation;

use App\Models\Management\Space;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\KnowledgeResolver;
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
        private readonly TranslationUnits $units,
        private readonly KnowledgeResolver $knowledge,
        private readonly GlossaryChecker $checker,
    ) {}

    /**
     * Status per unit ID: `missing` (no translation entry yet), `empty`, `stale`
     * (with `previous_source`, the text it was translated from), `same_as_source`,
     * `current`, `inherited` (empty, a fallback locale fills it) or
     * `not_in_translation` (a standalone translation lacks the block). Units with
     * an empty source are left out. With `$withFindings`, a saved translation that
     * breaks the glossary carries its `findings` ({@see GlossaryChecker}).
     *
     * @return array{locale: string, source_locale: string, translation_id: string|null, units: array<string, array{status: string, previous_source?: string, findings?: list<array<string, string>>}>}
     *
     * @throws ToolError for an unknown locale or an entry with no source to translate from
     */
    public function of(Space $space, string $contentId, string $locale, bool $withFindings = false): array
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

        if ($withFindings) {
            foreach ($this->findings($space, $destination) as $id => $findings) {
                if (isset($units[$id])) {
                    $units[$id]['findings'] = $findings;
                }
            }
        }

        return [
            'locale' => $destination->locale,
            'source_locale' => $destination->sourceLocale,
            'translation_id' => $destination->translation?->id,
            'units' => $units,
        ];
    }

    /**
     * Glossary findings per unit of the saved translation, plain text on both sides.
     *
     * @return array<string, list<array<string, string>>>
     */
    private function findings(Space $space, TranslationDestination $destination): array
    {
        $concepts = $this->knowledge->glossaryConcepts($space);

        if ($concepts === [] || $destination->translation === null) {
            return [];
        }

        $schema = $destination->source->block?->schema?->toArray() ?? [];
        $targets = $this->units->texts($destination->translation->getCurrentContent(), $schema);
        $findings = [];

        foreach ($this->units->collect($destination->source->getCurrentContent(), $schema) as $id => $unit) {
            $target = $targets[$id] ?? '';

            if (TranslationUnits::isBlank($unit['value']) || TranslationUnits::isBlank($target)) {
                continue;
            }

            $plain = fn (string $text) => $unit['type'] === 'richtext' ? TranslationAssist::plain($text) : $text;
            $found = $this->checker->check($plain($unit['value']), $plain($target), $destination->sourceLocale, $destination->locale, $concepts);

            if ($found !== []) {
                $findings[$id] = $found;
            }
        }

        return $findings;
    }
}
