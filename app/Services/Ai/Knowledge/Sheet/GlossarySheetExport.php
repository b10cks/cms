<?php

namespace App\Services\Ai\Knowledge\Sheet;

use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\Space;
use App\Services\Ai\Knowledge\Concepts\ConceptRow;
use App\Services\Ai\Knowledge\Concepts\GlossaryConceptRows;
use App\Services\Ai\Knowledge\Concepts\GlossaryLocales;
use Generator;

/**
 * The glossary in the format b10cks sets: one row per concept, all languages,
 * default language first. It reads back as no change.
 */
final class GlossarySheetExport
{
    private const PAGE = 500;

    /** @var list<string> */
    private array $locales;

    public function __construct(private readonly AiGlossary $glossary, private readonly ?Space $space)
    {
        $this->locales = $this->scan();
    }

    /** @return list<string> */
    public function headings(): array
    {
        return self::headingsFor($this->locales);
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    public static function headingsFor(array $locales): array
    {
        $headings = GlossarySheetColumns::SHARED;

        foreach ($locales as $locale) {
            foreach (GlossarySheetColumns::PER_LANGUAGE as $column) {
                $headings[] = "{$column}:{$locale}";
            }
        }

        return $headings;
    }

    /** @return list<string> the columns a template shows: the space's languages, or the glossary's */
    public function templateLocales(): array
    {
        return $this->locales !== [] ? $this->locales : [(string) config('app.locale', 'en')];
    }

    /** @return Generator<int, list<string>> */
    public function rows(): Generator
    {
        foreach ((new GlossaryConceptRows)->all($this->glossary, $this->space, self::PAGE) as $row) {
            yield $this->cells($row);
        }
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    public static function exampleRow(array $locales): array
    {
        $cells = ['example-concept', 'no', 'A made up row. Replace or delete it before you import.'];

        foreach ($locales as $index => $locale) {
            array_push(
                $cells,
                "Example term {$locale}",
                $index === 0 ? 'Example to avoid; Second example*' : '',
                $index === 0 ? 'Example spelling' : '',
            );
        }

        return $cells;
    }

    /** @return list<string> */
    private function cells(ConceptRow $row): array
    {
        $cells = [(string) $row->key, $row->keepExact ? 'yes' : 'no', (string) $row->note];

        foreach ($this->locales as $locale) {
            $language = $row->languages[$locale] ?? null;

            array_push(
                $cells,
                (string) ($language['use'] ?? ''),
                GlossarySheetColumns::joinList($language['avoid'] ?? []),
                GlossarySheetColumns::joinList($language['also'] ?? []),
            );
        }

        return $cells;
    }

    /**
     * The column locales, default language first.
     *
     * @return list<string>
     */
    private function scan(): array
    {
        $stored = AiGlossaryTerm::query()->inGlossary($this->glossary->id)->toBase()->distinct()->pluck('locale')->all();

        return array_column((new GlossaryLocales($this->space, array_map('strval', $stored)))->available(), 'code');
    }
}
