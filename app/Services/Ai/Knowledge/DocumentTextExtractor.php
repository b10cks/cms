<?php

namespace App\Services\Ai\Knowledge;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/** Plain text from uploaded PDF, DOCX, markdown, text and CSV files, as a markdown draft. */
class DocumentTextExtractor
{
    public const MAX_CHARS = 500_000;

    public function extract(string $path, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            'md', 'markdown', 'txt', 'csv' => (string) file_get_contents($path),
            default => throw new RuntimeException("Unsupported file type: .{$extension}"),
        };

        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }

    private function pdf(string $path): string
    {
        return (new PdfParser)->parseFile($path)->getText();
    }

    /**
     * Paragraphs from word/document.xml; heading styles become markdown headings
     * so the chunker can split on them.
     */
    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The DOCX file cannot be opened.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('The DOCX file has no document body.');
        }

        $dom = new \DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $lines = [];
        foreach ($xpath->query('//w:body//w:p') ?: [] as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) ?: [] as $node) {
                $text .= match ($node->localName) {
                    'tab' => "\t",
                    'br' => "\n",
                    default => $node->textContent,
                };
            }

            $style = $xpath->query('./w:pPr/w:pStyle/@w:val', $paragraph)?->item(0)?->nodeValue ?? '';
            if (preg_match('/^(?:Heading|berschrift)(\d)$/i', $style, $match) && trim($text) !== '') {
                $text = str_repeat('#', min(6, (int) $match[1])).' '.trim($text);
            }

            $lines[] = $text;
        }

        return implode("\n\n", $lines);
    }
}
