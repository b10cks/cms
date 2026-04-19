<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class XliffAssetDataDriver extends BaseAssetDataDriver
{
    public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response {
        $filename = $this->generateFilename($space, 'xlf');

        return new StreamedResponse(function () use ($space, $assets, $assetFields, $languages) {
            echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
            echo '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">' . PHP_EOL;

            foreach ($languages as $language) {
                $langCode = $language['code'];

                echo "  <file source-language=\"en\" target-language=\"{$langCode}\" datatype=\"plaintext\">" . PHP_EOL;
                echo '    <body>' . PHP_EOL;

                foreach ($assets as $asset) {
                    $data = $asset->data ?? [];
                    $effectiveFields = $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);

                    foreach ($effectiveFields as $field) {
                        $key = $field['key'];
                        $transUnitId = "{$asset->id}_{$key}";

                        $source = $data['fields']['_default'][$key] ?? '';
                        $target = $data['fields'][$langCode][$key] ?? '';

                        echo "      <trans-unit id=\"{$transUnitId}\">" . PHP_EOL;
                        echo '        <source>' . htmlspecialchars($source, ENT_XML1, 'UTF-8') . '</source>' . PHP_EOL;
                        echo '        <target>' . htmlspecialchars($target, ENT_XML1, 'UTF-8') . '</target>' . PHP_EOL;
                        echo "        <note>Asset: {$asset->filename}, Field: {$key}</note>" . PHP_EOL;
                        echo '      </trans-unit>' . PHP_EOL;
                    }
                }

                echo '    </body>' . PHP_EOL;
                echo '  </file>' . PHP_EOL;
            }

            echo '</xliff>' . PHP_EOL;
        }, 200, [
            'Content-Type' => 'application/x-xliff+xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parseFile(UploadedFile $file): array
    {
        $rows = [];

        try {
            $dom = new DOMDocument();
            @$dom->load($file->getRealPath());

            $files = $dom->getElementsByTagName('file');
            if ($files->length === 0) {
                throw new \RuntimeException('No files found in XLIFF document');
            }

            $assetsByFile = [];

            foreach ($files as $fileElement) {
                $targetLang = $fileElement->getAttribute('target-language');

                if (empty($targetLang)) {
                    continue;
                }

                $transUnits = $fileElement->getElementsByTagName('trans-unit');

                foreach ($transUnits as $transUnit) {
                    $transUnitId = $transUnit->getAttribute('id');
                    [$assetId, $fieldKey] = explode('_', $transUnitId, 2);

                    $targetElement = $transUnit->getElementsByTagName('target')->item(0);
                    $target = $targetElement ? trim($targetElement->textContent) : '';

                    if (!isset($assetsByFile[$assetId])) {
                        $assetsByFile[$assetId] = [];
                    }

                    $assetsByFile[$assetId][$fieldKey . '_' . $targetLang] = $target;
                }
            }

            // Convert to array format compatible with import
            foreach ($assetsByFile as $assetId => $rowData) {
                $rowData['id'] = $assetId;
                $rows[] = $rowData;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to parse XLIFF file: ' . $e->getMessage());
        }

        return $rows;
    }

    public function validate(
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): array {
        $errors = [];

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xlf', 'xliff', 'xml'])) {
            $errors[] = 'File must be an XLIFF file (xlf, xliff, xml)';

            return $errors;
        }

        try {
            $dom = new DOMDocument();
            @$dom->load($file->getRealPath());

            $xliffElement = $dom->documentElement;
            if ($xliffElement->tagName !== 'xliff') {
                $errors[] = 'Root element must be "xliff"';

                return $errors;
            }

            $files = $dom->getElementsByTagName('file');
            if ($files->length === 0) {
                $errors[] = 'XLIFF must contain at least one "file" element';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unable to parse XLIFF file: ' . $e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::XLIFF->value;
    }
}
