<?php

namespace App\Services\Ai\Knowledge;

use App\DTOs\ImportExport\ImportResult;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryTerm;
use App\Services\ImportExport\WritesCsvDownload;
use App\Services\ImportExport\WritesXlsxDownload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Glossary import and export as CSV, XLSX and TBX. Spreadsheet columns:
 * source_locale, term, case_sensitive, do_not_translate, forbidden (separated
 * by `|`), note, then one `translation:<locale>` column per locale. Imports
 * upsert by source locale and term.
 */
class GlossaryTransfer
{
    use WritesCsvDownload;
    use WritesXlsxDownload;

    public const FORMATS = ['csv', 'xlsx', 'tbx'];

    private const BASE_COLUMNS = ['source_locale', 'term', 'case_sensitive', 'do_not_translate', 'forbidden', 'note'];

    public function export(AiGlossary $glossary, string $format): Response
    {
        $terms = $glossary->terms()->orderBy('source_locale')->orderBy('term')->get();
        $filename = Str::slug($glossary->name ?: 'glossary').'.'.$format;

        if ($format === 'tbx') {
            return response($this->toTbx($terms->all()), 200, [
                'Content-Type' => 'application/x-tbx+xml',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        $locales = $terms->flatMap(fn (AiGlossaryTerm $term) => array_keys($term->translations ?? []))->unique()->sort()->values()->all();
        $headings = [...self::BASE_COLUMNS, ...array_map(fn (string $locale) => "translation:{$locale}", $locales)];
        $rows = $terms->map(fn (AiGlossaryTerm $term) => [
            $term->source_locale,
            $term->term,
            $term->case_sensitive ? '1' : '0',
            $term->do_not_translate ? '1' : '0',
            implode('|', $term->forbidden ?? []),
            (string) $term->note,
            ...array_map(fn (string $locale) => $term->translations[$locale] ?? '', $locales),
        ]);

        return $format === 'csv'
            ? $this->csvDownload($headings, $rows, $filename)
            : $this->xlsxDownload($headings, $rows, $filename);
    }

    public function import(AiGlossary $glossary, UploadedFile $file): ImportResult
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $rows = match ($extension) {
            'tbx', 'xml' => $this->fromTbx((string) file_get_contents($file->getRealPath())),
            'csv', 'xlsx' => $this->fromSpreadsheet($file->getRealPath()),
            default => throw ValidationException::withMessages(['file' => ['Use a CSV, XLSX or TBX file.']]),
        };

        $existing = $glossary->terms()->get()->keyBy(fn (AiGlossaryTerm $term) => GlossaryTerm::fromModel($term)->key());
        $successes = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $term = trim((string) ($row['term'] ?? ''));
            $locale = trim((string) ($row['source_locale'] ?? ''));

            if ($term === '' || $locale === '') {
                $errors[] = ['row' => $index + 1, 'message' => 'A term and its source locale are required.'];

                continue;
            }

            $attributes = [
                'source_locale' => $locale,
                'term' => mb_substr($term, 0, 255),
                'translations' => array_filter($row['translations'] ?? [], fn ($value) => \is_string($value) && trim($value) !== ''),
                'case_sensitive' => (bool) ($row['case_sensitive'] ?? false),
                'do_not_translate' => (bool) ($row['do_not_translate'] ?? false),
                'forbidden' => array_values(array_filter($row['forbidden'] ?? [], fn ($value) => \is_string($value) && trim($value) !== '')),
                'note' => ($row['note'] ?? '') !== '' ? $row['note'] : null,
            ];

            $key = strtolower($locale).'|'.mb_strtolower($attributes['term']);
            $model = $existing[$key] ?? new AiGlossaryTerm(['glossary_id' => $glossary->id]);
            $model->fill($attributes)->save();
            $existing[$key] = $model;
            $successes[] = ['row' => $index + 1, 'term' => $model->term];
        }

        return new ImportResult(successes: $successes, errors: $errors);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromSpreadsheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        $headings = array_map(fn ($value) => strtolower(trim((string) $value)), array_shift($sheet) ?? []);

        if (! \in_array('term', $headings, true)) {
            throw ValidationException::withMessages(['file' => ['The first row must name the columns, including "term".']]);
        }

        return array_values(array_map(function (array $cells) use ($headings) {
            $row = ['translations' => []];
            foreach ($headings as $i => $heading) {
                $value = trim((string) ($cells[$i] ?? ''));
                if (str_starts_with($heading, 'translation:')) {
                    $row['translations'][substr($heading, 12)] = $value;
                } elseif ($heading === 'forbidden') {
                    $row['forbidden'] = $value === '' ? [] : array_map('trim', explode('|', $value));
                } elseif (\in_array($heading, ['case_sensitive', 'do_not_translate'], true)) {
                    $row[$heading] = \in_array(strtolower($value), ['1', 'true', 'yes', 'x'], true);
                } else {
                    $row[$heading] = $value;
                }
            }

            return $row;
        }, array_filter($sheet, fn (array $cells) => array_filter($cells, fn ($value) => trim((string) $value) !== '') !== [])));
    }

    /**
     * TBX v2 (martif/termEntry/langSet/tig) and v3 (conceptEntry/langSec/termSec).
     * The first language section is the source; deprecated terms are forbidden variants.
     *
     * @return list<array<string, mixed>>
     */
    private function fromTbx(string $xml): array
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw ValidationException::withMessages(['file' => ['The TBX file is not valid XML.']]);
        }

        $xpath = new \DOMXPath($dom);
        $rows = [];

        foreach ($xpath->query('//*[local-name()="conceptEntry" or local-name()="termEntry"]') ?: [] as $entry) {
            $row = ['translations' => [], 'forbidden' => []];
            $note = $xpath->query('./*[local-name()="descrip" or local-name()="note"]', $entry)?->item(0)?->textContent;
            $row['note'] = $note !== null ? trim($note) : '';
            $row['do_not_translate'] = $xpath->query('.//*[local-name()="termNote" and @type="b10cks:doNotTranslate"]', $entry)?->length > 0;

            foreach ($xpath->query('./*[local-name()="langSec" or local-name()="langSet"]', $entry) ?: [] as $langSec) {
                $locale = $langSec->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: $langSec->getAttribute('xml:lang');

                foreach ($xpath->query('./*[local-name()="termSec" or local-name()="tig" or local-name()="ntig"]', $langSec) ?: [] as $termSec) {
                    $term = trim((string) $xpath->query('.//*[local-name()="term"]', $termSec)?->item(0)?->textContent);
                    $status = (string) $xpath->query('.//*[local-name()="termNote" and @type="administrativeStatus"]', $termSec)?->item(0)?->textContent;

                    if ($term === '') {
                        continue;
                    }

                    if (str_starts_with($status, 'deprecated') || str_starts_with($status, 'superseded')) {
                        $row['forbidden'][] = $term;
                    } elseif (! isset($row['term'])) {
                        $row['term'] = $term;
                        $row['source_locale'] = $locale;
                    } elseif ($locale !== $row['source_locale'] && ! isset($row['translations'][$locale])) {
                        $row['translations'][$locale] = $term;
                    }
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<AiGlossaryTerm>  $terms
     */
    private function toTbx(array $terms): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $ns = 'urn:iso:std:iso:30042:ed-2';
        $tbx = $dom->appendChild($dom->createElementNS($ns, 'tbx'));
        $tbx->setAttribute('type', 'TBX-Basic');
        $tbx->setAttribute('style', 'dca');
        $body = $tbx->appendChild($dom->createElementNS($ns, 'text'))->appendChild($dom->createElementNS($ns, 'body'));

        $termSec = function (\DOMElement $langSec, string $term, ?string $status = null) use ($dom, $ns): void {
            $sec = $langSec->appendChild($dom->createElementNS($ns, 'termSec'));
            $sec->appendChild($dom->createElementNS($ns, 'term'))->appendChild($dom->createTextNode($term));
            if ($status !== null) {
                $note = $sec->appendChild($dom->createElementNS($ns, 'termNote'));
                $note->setAttribute('type', 'administrativeStatus');
                $note->appendChild($dom->createTextNode($status));
            }
        };

        foreach ($terms as $term) {
            $entry = $body->appendChild($dom->createElementNS($ns, 'conceptEntry'));
            $entry->setAttribute('id', $term->id);

            if ($term->note) {
                $descrip = $entry->appendChild($dom->createElementNS($ns, 'descrip'));
                $descrip->setAttribute('type', 'definition');
                $descrip->appendChild($dom->createTextNode($term->note));
            }

            $source = $entry->appendChild($dom->createElementNS($ns, 'langSec'));
            $source->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $term->source_locale);
            $termSec($source, $term->term, 'preferredTerm-admn-sts');
            if ($term->do_not_translate) {
                $note = $source->lastChild->appendChild($dom->createElementNS($ns, 'termNote'));
                $note->setAttribute('type', 'b10cks:doNotTranslate');
                $note->appendChild($dom->createTextNode('true'));
            }
            foreach ($term->forbidden ?? [] as $variant) {
                $termSec($source, $variant, 'deprecatedTerm-admn-sts');
            }

            foreach ($term->translations ?? [] as $locale => $translation) {
                $langSec = $entry->appendChild($dom->createElementNS($ns, 'langSec'));
                $langSec->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', (string) $locale);
                $termSec($langSec, $translation);
            }
        }

        return (string) $dom->saveXML();
    }
}
