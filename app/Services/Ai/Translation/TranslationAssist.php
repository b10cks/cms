<?php

namespace App\Services\Ai\Translation;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Ai\Knowledge\Concepts\GlossaryTextCheck;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Run\ToolError;

/**
 * What a person translating one unit by hand can lean on, without an AI call:
 * the glossary terms in its source and what a draft breaks, similar pairs from
 * translation memory, and the unit's text in the entry's other locales. Every
 * text is plain: rich text drops its markup.
 */
class TranslationAssist
{
    private const MEMORY_LIMIT = 5;

    /** Memory matches below this score are noise to a person, though still useful as AI examples. */
    private const MEMORY_MIN_SCORE = 0.3;

    public function __construct(
        private readonly TranslationDestinations $destinations,
        private readonly TranslationUnits $units,
        private readonly TranslationMemory $memory,
        private readonly KnowledgeResolver $knowledge,
        private readonly GlossaryTextCheck $check,
    ) {}

    /**
     * @param  string|null  $draft  the translation to check, by default the saved one
     * @param  bool  $withGlossary  false leaves `glossary` null, for people who may not read the space's AI knowledge
     * @return array{unit: string, type: string, source: string, target: string, glossary: array{terms: list<array<string, mixed>>, findings: list<array<string, mixed>>|null}|null, memory: list<array<string, mixed>>, locales: list<array{locale: string, text: string}>}|null null for an unknown unit
     *
     * @throws ToolError for an unknown locale or an entry with no source to translate from
     */
    public function for(Space $space, string $contentId, string $locale, string $unitId, ?string $draft, bool $withGlossary): ?array
    {
        $destination = $this->destinations->resolve($space, $contentId, $locale);
        $schema = $destination->source->block?->schema?->toArray() ?? [];
        $unit = $this->units->collect($destination->source->getCurrentContent(), $schema)[$unitId] ?? null;

        if ($unit === null) {
            return null;
        }

        $plain = fn (string $text) => $unit['type'] === 'richtext' ? self::plain($text) : $text;
        $source = $plain($unit['value']);
        $target = $plain($draft ?? ($destination->translation ? ($this->units->texts($destination->translation->getCurrentContent(), $schema)[$unitId] ?? '') : ''));
        $concepts = $this->knowledge->glossaryConcepts($space);

        $glossary = null;
        if ($withGlossary && ! TranslationUnits::isBlank($source)) {
            $check = $this->check->run(PolicySnapshot::fromConcepts($concepts), $source, $destination->sourceLocale, $destination->locale, TranslationUnits::isBlank($target) ? null : $target);
            $glossary = [
                'terms' => array_map(fn (array $term) => array_intersect_key($term, array_flip(['concept_id', 'term', 'required', 'do_not_translate', 'forbidden', 'note', 'matches'])), $check['terms']),
                'findings' => $check['findings'],
            ];
        }

        $hits = TranslationUnits::isBlank($unit['value']) ? [] : $this->memory->similar($unit['value'], $destination->sourceLocale, $destination->locale, self::MEMORY_LIMIT, $concepts);
        // A match that already stands in the field suggests nothing.
        $hits = array_values(array_filter($hits, fn (array $hit) => $hit['score'] >= self::MEMORY_MIN_SCORE
            && $plain($hit['target']) !== $target));
        $names = Content::query()->whereIn('id', array_filter(array_column($hits, 'content_id')))->pluck('name', 'id');
        $memory = array_map(fn (array $hit) => [
            ...$hit,
            'source' => $plain($hit['source']),
            'target' => $plain($hit['target']),
            'content_name' => $hit['content_id'] !== null ? ($names[$hit['content_id']] ?? null) : null,
        ], $hits);

        return [
            'unit' => $unitId,
            'type' => $unit['type'],
            'source' => $source,
            'target' => $target,
            'glossary' => $glossary,
            'memory' => $memory,
            'locales' => $this->otherLocales($destination, $schema, $unitId, $plain),
        ];
    }

    /**
     * The unit's own text in the entry's other translations. An overlay row that
     * leaves it empty falls back and is left out.
     *
     * @param  array<string, mixed>  $schema
     * @param  callable(string): string  $plain
     * @return list<array{locale: string, text: string}>
     */
    private function otherLocales(TranslationDestination $destination, array $schema, string $unitId, callable $plain): array
    {
        return Content::query()
            ->with('current_version')
            ->where('i18n_parent_id', $destination->source->id)
            ->where('language_iso', '!=', $destination->locale)
            ->whereNull('deleted_at')
            ->orderBy('language_iso')
            ->get()
            ->map(fn (Content $row) => ['locale' => (string) $row->language_iso, 'text' => $plain($this->units->texts($row->getCurrentContent(), $schema)[$unitId] ?? '')])
            ->reject(fn (array $row) => TranslationUnits::isBlank($row['text']))
            ->values()
            ->all();
    }

    /** Rich text HTML as plain text, one line per block. */
    public static function plain(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/(p|h[1-6]|li|blockquote|pre)>/i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }
}
