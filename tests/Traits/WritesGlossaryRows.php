<?php

namespace Tests\Traits;

use App\Models\Management\AiGlossary;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;

/** Glossary rows for a test, written the way the product writes them. */
trait WritesGlossaryRows
{
    /**
     * @param  array<string, string|array<string, mixed>>  $languages  a string is that language's Use
     * @param  array<string, mixed>  $fields  key, note, keep_exact
     * @return string the row's id
     */
    protected function glossaryRow(AiGlossary $glossary, array $languages, array $fields = []): string
    {
        return GlossaryConcepts::transaction($glossary, fn (GlossaryConcepts $concepts) => $concepts->create([...$fields, 'languages' => self::rowLanguages($languages)]));
    }

    /**
     * @param  array<string, string|array<string, mixed>>  $languages
     * @param  array<string, mixed>  $fields
     */
    protected function changeGlossaryRow(AiGlossary $glossary, string $id, array $languages, array $fields = []): void
    {
        GlossaryConcepts::transaction($glossary, fn (GlossaryConcepts $concepts) => $concepts->update($id, [...$fields, 'languages' => self::rowLanguages($languages)]));
    }

    /**
     * @param  array<string, string|array<string, mixed>>  $languages
     * @return array<string, array<string, mixed>>
     */
    private static function rowLanguages(array $languages): array
    {
        return array_map(static fn (string|array $values) => \is_string($values) ? ['use' => $values] : $values, $languages);
    }
}
