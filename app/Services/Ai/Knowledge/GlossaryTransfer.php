<?php

namespace App\Services\Ai\Knowledge;

use App\DTOs\ImportExport\ImportResult;
use App\Exceptions\GlossaryConceptConflict;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Knowledge\Concepts\ConceptRow;
use App\Services\Ai\Knowledge\Concepts\GlossaryConceptRows;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use App\Services\Ai\Knowledge\Sheet\GlossarySheetExport;
use App\Services\Ai\Knowledge\Sheet\GlossarySheetImport;
use App\Services\Ai\Knowledge\Sheet\GlossarySheetReader;
use App\Services\Ai\Knowledge\Sheet\GlossarySheetWorkbook;
use App\Services\ImportExport\WritesCsvDownload;
use App\Services\System\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Glossary import and export. CSV and XLSX use the format b10cks sets, see
 * {@see GlossarySheetImport} and docs/guides/glossary-sheet.md. A TBX entry is one row,
 * read as {@see fromTbx()} says. A
 * sheet whose header holds `source_locale` is the older format, with `forbidden`
 * separated by `|` and one `translation:<locale>` column per locale. Both are read into
 * the same row input and go through the writer.
 *
 * @phpstan-import-type ConceptInput from GlossaryConcepts
 */
class GlossaryTransfer
{
    use WritesCsvDownload;

    public const FORMATS = ['csv', 'xlsx', 'tbx'];

    public const TEMPLATE_FORMATS = ['csv', 'xlsx'];

    private const PROGRESS_EVERY = 100;

    private const OLD_FORMAT_COLUMN = 'source_locale';

    private const MAX_WORDING_LENGTH = 255;

    /** Excel reads a CSV as the local code page unless it starts with this. */
    private const BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly AuditService $audit) {}

    public function export(AiGlossary $glossary, string $format, bool $template = false): Response
    {
        $name = Str::slug($glossary->name ?: 'glossary').($template ? '-template' : '');
        $filename = "{$name}.{$format}";

        if ($format === 'tbx') {
            return response($this->toTbx((new GlossaryConceptRows)->all($glossary, null)), 200, [
                'Content-Type' => 'application/x-tbx+xml',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        $space = $glossary->space_id !== null ? Space::query()->find($glossary->space_id) : null;
        $sheet = new GlossarySheetExport($glossary, $space);

        if ($template) {
            $locales = $sheet->templateLocales();
            $headings = GlossarySheetExport::headingsFor($locales);
            $rows = [GlossarySheetExport::exampleRow($locales)];
        } else {
            $headings = $sheet->headings();
            $rows = $sheet->rows();
        }

        if ($format === 'csv') {
            $headings[0] = self::BOM.$headings[0];

            return $this->csvDownload($headings, $rows, $filename, escape: '');
        }

        return new StreamedResponse(
            static fn () => (new GlossarySheetWorkbook)->save($headings, $rows, 'php://output'),
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ],
        );
    }

    /** @return \Generator<int, array{processed: int, total: int}, mixed, ImportResult> */
    public function import(AiGlossary $glossary, UploadedFile $file, ?User $user = null): \Generator
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (\in_array($extension, ['csv', 'xlsx'], true)) {
            $sheet = GlossarySheetReader::read($file->getRealPath(), $extension);
            $headers = array_map(static fn (string $header) => strtolower($header), $sheet['headers']);

            if (! \in_array(self::OLD_FORMAT_COLUMN, $headers, true)) {
                return yield from (new GlossarySheetImport($glossary, $file, $sheet, $user, $this->audit))->run();
            }

            unset($sheet);
        }

        return yield from match ($extension) {
            'tbx', 'xml' => $this->importEntries($glossary, $this->fromTbx((string) file_get_contents($file->getRealPath()))),
            'csv', 'xlsx' => $this->importEntries($glossary, $this->fromSpreadsheet($file->getRealPath())),
            default => throw ValidationException::withMessages(['file' => ['Use a CSV, XLSX or TBX file.']]),
        };
    }

    /**
     * Applies file rows through the writer. A file row updates the row {@see matchingRow()}
     * finds, and a refused row becomes a row error.
     *
     * @param  list<ConceptInput|array{error: string}>  $entries
     * @return \Generator<int, array{processed: int, total: int}, mixed, ImportResult>
     */
    private function importEntries(AiGlossary $glossary, array $entries): \Generator
    {
        $total = \count($entries);
        $successes = [];
        $errors = [];

        yield ['processed' => 0, 'total' => $total];

        $concepts = GlossaryConcepts::open($glossary);

        foreach ($entries as $index => $entry) {
            $refused = $entry['error'] ?? self::tooLong($entry);

            if ($refused !== null) {
                $errors[] = ['row' => $index + 1, 'message' => $refused];

                continue;
            }

            $wordings = array_filter(array_map(static fn (array $values) => $values['use'] ?? $values['avoid'][0] ?? null, $entry['languages'] ?? []));
            $rowId = self::matchingRow($concepts, $wordings);

            try {
                $rowId !== null ? $concepts->update($rowId, $entry) : $concepts->create($entry);
                $successes[] = ['row' => $index + 1, 'term' => reset($wordings)];
            } catch (GlossaryConceptConflict $conflict) {
                $errors[] = ['row' => $index + 1, 'message' => $conflict->getMessage()];
            }

            if (($index + 1) % self::PROGRESS_EVERY === 0) {
                yield ['processed' => $index + 1, 'total' => $total];
            }
        }

        try {
            $concepts->commit();
        } catch (GlossaryConceptConflict $conflict) {
            throw ValidationException::withMessages(['file' => [$conflict->getMessage()]]);
        }

        yield ['processed' => $total, 'total' => $total];

        return new ImportResult(successes: $successes, errors: $errors);
    }

    /**
     * The row a file row updates. Its first language decides. Another language finds a
     * row only if that row has no wording in the first language, because the update would
     * replace wording the file never named. Otherwise the file row is new, and the writer
     * refuses the wording it shares with that row.
     *
     * @param  array<string, string>  $wordings  by language, the file row's first language first
     */
    private static function matchingRow(GlossaryConcepts $concepts, array $wordings): ?string
    {
        $first = GlossaryMatcher::normalizeLocale((string) array_key_first($wordings));

        foreach ($wordings as $locale => $wording) {
            $id = $concepts->rowIdForWording((string) $locale, $wording);

            if ($id !== null && (GlossaryMatcher::normalizeLocale((string) $locale) === $first || ! isset($concepts->rowFacts($id)['terms'][$first]))) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The file row's wording over the limit a person's edit has, as a message.
     *
     * @param  ConceptInput  $entry
     */
    private static function tooLong(array $entry): ?string
    {
        foreach ($entry['languages'] ?? [] as $code => $language) {
            foreach ([$language['use'] ?? null, ...$language['avoid'] ?? [], ...$language['also'] ?? []] as $wording) {
                if ($wording !== null && mb_strlen($wording) > self::MAX_WORDING_LENGTH) {
                    return "A wording in {$code} is longer than ".self::MAX_WORDING_LENGTH.' characters: '.mb_substr($wording, 0, 40).'…';
                }
            }
        }

        return null;
    }

    /**
     * The older term format.
     *
     * @return list<ConceptInput|array{error: string}>
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

            if (($row['term'] ?? '') === '' || ($row['source_locale'] ?? '') === '') {
                return ['error' => 'A term and its source locale are required.'];
            }

            return LegacyGlossaryRows::input($row);
        }, array_filter($sheet, fn (array $cells) => array_filter($cells, fn ($value) => trim((string) $value) !== '') !== [])));
    }

    /**
     * TBX v2 (martif/termEntry/langSet/tig) and v3 (conceptEntry/langSec/termSec).
     * In each language the first preferred term is Use, deprecated terms are Avoid and
     * admitted terms and any further preferred term are Also written as.
     *
     * @return list<ConceptInput>
     */
    private function fromTbx(string $xml): array
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw ValidationException::withMessages(['file' => ['The TBX file is not valid XML.']]);
        }

        $xpath = new \DOMXPath($dom);
        $entries = [];

        foreach ($xpath->query('//*[local-name()="conceptEntry" or local-name()="termEntry"]') ?: [] as $entry) {
            $row = ['languages' => []];
            $note = trim((string) $xpath->query('./*[local-name()="descrip" or local-name()="note"]', $entry)?->item(0)?->textContent);

            if ($note !== '') {
                $row['note'] = $note;
            }

            if ($xpath->query('.//*[local-name()="termNote" and @type="b10cks:doNotTranslate"]', $entry)?->length > 0) {
                $row['keep_exact'] = true;
            }

            foreach ($xpath->query('./*[local-name()="langSec" or local-name()="langSet"]', $entry) ?: [] as $langSec) {
                // A section without a language reaches the writer, which makes it a row error.
                $locale = $langSec->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: $langSec->getAttribute('xml:lang');

                foreach ($xpath->query('./*[local-name()="termSec" or local-name()="tig" or local-name()="ntig"]', $langSec) ?: [] as $termSec) {
                    $term = trim((string) $xpath->query('.//*[local-name()="term"]', $termSec)?->item(0)?->textContent);
                    $status = (string) $xpath->query('.//*[local-name()="termNote" and @type="administrativeStatus"]', $termSec)?->item(0)?->textContent;

                    if ($term === '') {
                        continue;
                    }

                    if (str_starts_with($status, 'deprecated') || str_starts_with($status, 'superseded')) {
                        $row['languages'][$locale]['avoid'][] = $term;
                    } elseif (str_starts_with($status, 'admitted') || isset($row['languages'][$locale]['use'])) {
                        $row['languages'][$locale]['also'][] = $term;
                    } else {
                        $row['languages'][$locale]['use'] = $term;
                    }
                }
            }

            $entries[] = $row;
        }

        return $entries;
    }

    /**
     * @param  iterable<ConceptRow>  $rows
     */
    private function toTbx(iterable $rows): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $ns = 'urn:iso:std:iso:30042:ed-2';
        $tbx = $dom->appendChild($dom->createElementNS($ns, 'tbx'));
        $tbx->setAttribute('type', 'TBX-Basic');
        $tbx->setAttribute('style', 'dca');
        $body = $tbx->appendChild($dom->createElementNS($ns, 'text'))->appendChild($dom->createElementNS($ns, 'body'));

        $termSec = function (\DOMElement $langSec, string $term, string $status) use ($dom, $ns): \DOMElement {
            $sec = $langSec->appendChild($dom->createElementNS($ns, 'termSec'));
            $sec->appendChild($dom->createElementNS($ns, 'term'))->appendChild($dom->createTextNode($term));
            $note = $sec->appendChild($dom->createElementNS($ns, 'termNote'));
            $note->setAttribute('type', 'administrativeStatus');
            $note->appendChild($dom->createTextNode($status));

            return $sec;
        };

        foreach ($rows as $row) {
            $entry = $body->appendChild($dom->createElementNS($ns, 'conceptEntry'));
            $entry->setAttribute('id', $row->id);

            if ($row->note) {
                $descrip = $entry->appendChild($dom->createElementNS($ns, 'descrip'));
                $descrip->setAttribute('type', 'definition');
                $descrip->appendChild($dom->createTextNode($row->note));
            }

            $first = null;

            foreach ($row->languages as $locale => $language) {
                $langSec = $entry->appendChild($dom->createElementNS($ns, 'langSec'));
                $langSec->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', (string) $locale);

                if ($language['use'] !== null) {
                    $sec = $termSec($langSec, $language['use'], 'preferredTerm-admn-sts');
                    $first ??= $sec;
                }

                foreach ($language['avoid'] as $variant) {
                    $sec = $termSec($langSec, $variant, 'deprecatedTerm-admn-sts');
                    $first ??= $sec;
                }

                foreach ($language['also'] as $also) {
                    $termSec($langSec, $also, 'admittedTerm-admn-sts');
                }
            }

            if ($row->keepExact && $first !== null) {
                $note = $first->appendChild($dom->createElementNS($ns, 'termNote'));
                $note->setAttribute('type', 'b10cks:doNotTranslate');
                $note->appendChild($dom->createTextNode('true'));
            }
        }

        return (string) $dom->saveXML();
    }
}
