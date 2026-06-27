<?php

namespace App\Services\Ai\Support;

/**
 * Tolerant decoder for JSON produced by language models.
 *
 * Models routinely wrap JSON in ```json fences or surround it with prose even
 * when asked not to. This decodes the common cases: a clean payload, a fenced
 * payload, or a payload embedded in surrounding text (by extracting the first
 * balanced object/array). Returns null when nothing parseable is found.
 */
class JsonExtractor
{
    public static function decode(?string $raw, bool $associative = true): mixed
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = self::stripFences(trim($raw));

        $decoded = json_decode($text, $associative);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $candidate = self::extractBalanced($text);
        if ($candidate !== null) {
            $decoded = json_decode($candidate, $associative);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    public static function stripFences(string $text): string
    {
        $text = preg_replace('/^\s*```(?:json|javascript|js)?\s*\n?/i', '', $text);
        $text = preg_replace('/\n?```\s*$/', '', $text);

        return trim($text);
    }

    /**
     * Extract the first balanced JSON object or array from arbitrary text,
     * respecting strings and escapes so braces inside string values do not
     * confuse the depth counter.
     */
    private static function extractBalanced(string $text): ?string
    {
        $length = strlen($text);
        $start = null;
        $open = '{';
        $close = '}';

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === '{') {
                $start = $i;
                $open = '{';
                $close = '}';
                break;
            }

            if ($text[$i] === '[') {
                $start = $i;
                $open = '[';
                $close = ']';
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
