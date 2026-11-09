<?php

namespace App\Services\Ai\Translation;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Ai\Run\ToolError;

/**
 * What a person translating one unit by hand can lean on, without an AI call:
 * similar pairs from translation memory and the unit's text in the entry's
 * other locales. Every
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
    ) {}

    /**
     * @param  string|null  $draft  the translation being written, by default the saved one
     * @return array{unit: string, type: string, source: string, target: string, memory: list<array<string, mixed>>, locales: list<array{locale: string, text: string}>}|null null for an unknown unit
     *
     * @throws ToolError for an unknown locale or an entry with no source to translate from
     */
    public function for(Space $space, string $contentId, string $locale, string $unitId, ?string $draft): ?array
    {
        $destination = $this->destinations->resolve($space, $contentId, $locale);
        $schema = $destination->source->block?->schema?->toArray() ?? [];
        $unit = $this->units->collect($destination->source->getCurrentContent(), $schema)[$unitId] ?? null;

        if ($unit === null) {
            return null;
        }

        $plain = fn (string $text) => $unit['type'] === 'richtext' ? self::plain($text) : $text;
        $target = $plain($draft ?? ($destination->translation ? ($this->units->texts($destination->translation->getCurrentContent(), $schema)[$unitId] ?? '') : ''));

        $hits = TranslationUnits::isBlank($unit['value']) ? [] : $this->memory->similar($unit['value'], $destination->sourceLocale, $destination->locale, self::MEMORY_LIMIT);
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
            'source' => $plain($unit['value']),
            'target' => $target,
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
