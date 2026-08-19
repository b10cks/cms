<?php

namespace App\Services\ContentData\Drivers;

use App\DTOs\ContentData\TranslationDocument;
use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use DOMDocument;
use DOMElement;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * XLIFF 1.2 driver — one <file> per (content document, target language). The content
 * id travels in the file's `original` attribute; each translatable unit is a
 * <trans-unit> whose stable id addresses the field. Richtext units carry HTML in
 * <source>/<target> (XML-escaped), round-tripped losslessly by the applier.
 */
class XliffContentDataDriver extends BaseContentDataDriver
{
    /**
     * XLIFF models the source as read-only `<source>`, so `$gridMode` changes nothing
     * here: an edited source column cannot be expressed in this format and will not
     * round-trip. Use CSV, Excel, JSON or YAML for a full grid round trip.
     */
    public function export(Space $space, array $documents, bool $gridMode = false): Response
    {
        $filename = $this->generateFilename($space, 'xlf');

        return new StreamedResponse(function () use ($documents): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
            echo '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">' . PHP_EOL;

            foreach ($documents as $document) {
                foreach ($document->languages as $language) {
                    $this->writeFile($document, $language);
                }
            }

            echo '</xliff>' . PHP_EOL;
        }, 200, [
            'Content-Type' => 'application/x-xliff+xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function writeFile(TranslationDocument $document, string $language): void
    {
        $original = $this->escape($document->contentId);
        $sourceLang = $this->escape($document->sourceLanguage);
        $targetLang = $this->escape($language);
        $note = $this->escape($document->name . ' (' . $document->slug . ')');

        echo "  <file original=\"{$original}\" source-language=\"{$sourceLang}\" target-language=\"{$targetLang}\" datatype=\"html\">" . PHP_EOL;
        echo '    <header><note>' . $note . '</note></header>' . PHP_EOL;
        echo '    <body>' . PHP_EOL;

        foreach ($document->units as $unit) {
            $id = $this->escape($unit->id);
            $restype = $this->escape($unit->type);

            echo "      <trans-unit id=\"{$id}\" restype=\"{$restype}\">" . PHP_EOL;
            echo '        <source>' . $this->escape($unit->source) . '</source>' . PHP_EOL;
            echo '        <target>' . $this->escape($unit->targets[$language] ?? '') . '</target>' . PHP_EOL;
            echo '        <note>' . $this->escape($unit->note) . '</note>' . PHP_EOL;
            echo '      </trans-unit>' . PHP_EOL;
        }

        echo '    </body>' . PHP_EOL;
        echo '  </file>' . PHP_EOL;
    }

    public function parse(UploadedFile $file): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($file->getRealPath());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new \RuntimeException('Failed to parse XLIFF file');
        }

        $documents = [];

        foreach ($dom->getElementsByTagName('file') as $fileElement) {
            if (! $fileElement instanceof DOMElement) {
                continue;
            }

            $contentId = trim($fileElement->getAttribute('original'));
            $language = trim($fileElement->getAttribute('target-language'));

            if ($contentId === '' || $language === '') {
                continue;
            }

            $documents[$contentId] ??= ['content_id' => $contentId, 'targets' => []];

            foreach ($fileElement->getElementsByTagName('trans-unit') as $transUnit) {
                if (! $transUnit instanceof DOMElement) {
                    continue;
                }

                $unitId = trim($transUnit->getAttribute('id'));
                if ($unitId === '') {
                    continue;
                }

                $target = $transUnit->getElementsByTagName('target')->item(0);

                // A missing <target> means "not provided"; a present but empty one is
                // a deliberate clear, which the applier honours on a grid import.
                if ($target === null) {
                    continue;
                }

                $documents[$contentId]['targets'][$language][$unitId] = $target->textContent;
            }
        }

        return array_values($documents);
    }

    public function validate(UploadedFile $file): array
    {
        if ($error = $this->validateExtension($file->getClientOriginalExtension(), ['xlf', 'xliff', 'xml'])) {
            return [$error];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($file->getRealPath());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $dom->documentElement === null) {
            return ['Unable to parse XLIFF file'];
        }

        if ($dom->documentElement->localName !== 'xliff') {
            return ['Root element must be "xliff"'];
        }

        if ($dom->getElementsByTagName('file')->length === 0) {
            return ['XLIFF must contain at least one "file" element'];
        }

        return [];
    }

    public function getFormat(): string
    {
        return ImportExportFormat::XLIFF->value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
