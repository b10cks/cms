<?php

namespace App\Services\Ai\Knowledge\Concepts;

use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryConcept;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Tools\LookupGlossaryTool;

/**
 * Tries a text without a provider call, against saved wording only. The wording comes
 * from the lookup tool and the findings from the checker, so this answers what a run
 * would see under the same rows. {@see forGlossary()} is one glossary alone, which is
 * not what a run uses when a space has more than one.
 */
final readonly class GlossaryTextCheck
{
    public function __construct(private GlossaryChecker $checker) {}

    /**
     * @return array{terms: list<array<string, mixed>>, findings: list<array<string, mixed>>|null}
     */
    public function forGlossary(AiGlossary $glossary, string $text, string $sourceLocale, ?string $targetLocale, ?string $output): array
    {
        $concepts = AiGlossaryConcept::query()->where('glossary_id', $glossary->id)->with('terms')->orderBy('id')->get()
            ->map(GlossaryConcept::fromModel(...))->all();

        return $this->run(PolicySnapshot::fromConcepts($concepts), $text, $sourceLocale, $targetLocale, $output);
    }

    /**
     * @return array{terms: list<array<string, mixed>>, findings: list<array<string, mixed>>|null}
     */
    public function run(PolicySnapshot $policy, string $text, string $sourceLocale, ?string $targetLocale, ?string $output): array
    {
        $hits = $policy->activate($text, $sourceLocale, $targetLocale);
        $lookup = (new LookupGlossaryTool(static fn () => $policy))->execute([
            'text' => $text,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale ?? '',
        ]);

        $glossaries = AiGlossary::query()->whereIn('id', array_filter(array_map(static fn (array $hit) => $hit['concept']->glossaryId, $hits)))->pluck('name', 'id');

        // The tool lists its terms in activation order, so the two line up by position.
        $rows = [];

        foreach ($hits as $index => ['concept' => $concept, 'spans' => $spans]) {
            $rows[] = [
                ...$lookup['terms'][$index],
                'row_id' => $concept->id,
                'glossary_id' => $concept->glossaryId,
                'glossary_name' => $glossaries[$concept->glossaryId] ?? null,
                'matches' => $this->matches($spans, $text),
            ];
        }

        return [
            'terms' => $rows,
            'findings' => $output !== null
                ? $this->checker->check($text, $output, $sourceLocale, $targetLocale ?? $sourceLocale, $policy->concepts)
                : null,
        ];
    }

    /**
     * @param  list<array{start: int, end: int, text: string, avoid: bool}>  $spans  byte offsets
     * @return list<array{start: int, end: int, text: string}>
     */
    private function matches(array $spans, string $text): array
    {
        return array_map(static function (array $span) use ($text) {
            $start = self::utf16Length(substr($text, 0, $span['start']));

            return ['start' => $start, 'end' => $start + self::utf16Length($span['text']), 'text' => $span['text']];
        }, $spans);
    }

    /** Offsets in UTF-16 code units, which is what String.prototype.slice takes. */
    private static function utf16Length(string $text): int
    {
        return (int) (\strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }
}
