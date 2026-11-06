<?php

namespace App\Services\Ai\Knowledge\Sheet;

use App\Services\Ai\Knowledge\Concepts\GlossaryLocales;
use App\Services\Ai\Knowledge\GlossaryMatcher;

/**
 * The columns of a sheet and what a row of them means for the concept writer.
 * Headers are case-insensitive, in any order and any spelling of a locale. A blank
 * cell maps to an omitted key, which the writer keeps as stored.
 *
 * @phpstan-import-type ConceptInput from \App\Services\Ai\Knowledge\Concepts\GlossaryConcepts
 */
final class GlossarySheetColumns
{
    public const SHARED = ['concept', 'keep_exact', 'note'];

    public const PER_LANGUAGE = ['term', 'avoid', 'also'];

    /** The sheet says `term`, as a person does. Everywhere else it is the row's Use. */
    private const LANGUAGE_FIELDS = ['term' => 'use', 'avoid' => 'avoid', 'also' => 'also'];

    private const TRUE_WORDS = ['1', 'true', 'yes', 'y', 'x', 'ja', 'wahr'];

    private const FALSE_WORDS = ['0', 'false', 'no', 'n', 'nein', 'falsch'];

    /** @var array<int, string> position => shared column */
    private array $shared = [];

    /** @var array<int, array{field: string, code: string}> position => language column */
    private array $languages = [];

    /** @var list<string> locale codes that have a term or avoid column, in file order */
    private array $wordingLocales = [];

    /** @var list<string> */
    private array $unknown = [];

    private int $width;

    /** @param  array<int, string>  $headers */
    public function __construct(array $headers, GlossaryLocales $locales)
    {
        $this->width = $headers === [] ? 0 : max(array_keys($headers)) + 1;
        $seen = [];

        foreach ($headers as $position => $header) {
            $name = strtolower(trim($header));
            [$field, $locale] = array_pad(explode(':', $name, 2), 2, null);

            $normalized = $locale !== null ? GlossaryMatcher::normalizeLocale($locale) : null;
            $column = $normalized !== null ? "{$field}:{$normalized}" : (string) $field;

            if (isset($seen[$column])) {
                $this->unknown[] = $header;
            } elseif ($normalized === null && \in_array($field, self::SHARED, true)) {
                $seen[$column] = true;
                $this->shared[$position] = (string) $field;
            } elseif ($normalized !== null && isset(self::LANGUAGE_FIELDS[$field]) && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $normalized) === 1 && \strlen($normalized) <= 20) {
                $seen[$column] = true;
                $code = $locales->code($normalized);
                $this->languages[$position] = ['field' => (string) $field, 'code' => $code];

                if ($field !== 'also' && ! \in_array($code, $this->wordingLocales, true)) {
                    $this->wordingLocales[] = $code;
                }
            } else {
                $this->unknown[] = $header;
            }
        }
    }

    /** A sheet can update on `concept` alone, or match and create on `term:<l>` or `avoid:<l>`. */
    public function identifiesRows(): bool
    {
        return $this->wordingLocales !== [] || \in_array('concept', $this->shared, true);
    }

    /** @return list<string> */
    public function unknown(): array
    {
        return $this->unknown;
    }

    /** @return list<string> */
    public function wordingLocales(): array
    {
        return $this->wordingLocales;
    }

    /**
     * A cell past the last header is usually an unquoted separator that shifted the row.
     *
     * @param  array<int, string>  $cells
     */
    public function hasExtraCells(array $cells): bool
    {
        return $cells !== [] && max(array_keys($cells)) >= $this->width;
    }

    /**
     * @param  array<int, string>  $cells
     * @return array{input: ConceptInput, errors: list<array{column: string, message: string}>}
     */
    public function input(array $cells): array
    {
        $input = [];
        $errors = [];

        foreach ($this->shared as $position => $column) {
            $value = $cells[$position] ?? null;

            if ($value === null) {
                continue;
            }

            match ($column) {
                'concept' => $input['key'] = $value,
                'note' => $input['note'] = $value,
                'keep_exact' => $this->flag($input, $errors, 'keep_exact', $column, $value),
            };
        }

        foreach ($this->languages as $position => ['field' => $field, 'code' => $code]) {
            $value = $cells[$position] ?? null;

            if ($value === null) {
                continue;
            }

            $input['languages'][$code][self::LANGUAGE_FIELDS[$field]] = $field === 'term' ? $value : self::splitList($value);
        }

        return ['input' => $input, 'errors' => $errors];
    }

    /** The column a validation key of {@see input()} came from. */
    public function columnOf(string $key): string
    {
        $parts = explode('.', $key);

        if ($parts[0] === 'languages' && isset($parts[2])) {
            $column = array_search($parts[2], self::LANGUAGE_FIELDS, true);

            return "{$column}:{$parts[1]}";
        }

        return $parts[0] === 'key' ? 'concept' : $parts[0];
    }

    /**
     * Lists separate on `;`. A wording that holds one writes it as `\;`.
     *
     * @return list<string>
     */
    public static function splitList(string $value): array
    {
        $parts = preg_split('/(?<!\\\\);/', $value) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part) => trim(str_replace('\;', ';', $part)), $parts),
            static fn (string $part) => $part !== '',
        ));
    }

    /** @param  array<int, mixed>  $values */
    public static function joinList(array $values): string
    {
        return implode('; ', array_map(static fn ($value) => str_replace(';', '\;', (string) $value), $values));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array{column: string, message: string}>  $errors
     */
    private function flag(array &$input, array &$errors, string $field, string $column, string $value): void
    {
        $word = strtolower($value);

        if (\in_array($word, self::TRUE_WORDS, true)) {
            $input[$field] = true;
        } elseif (\in_array($word, self::FALSE_WORDS, true)) {
            $input[$field] = false;
        } else {
            $errors[] = ['column' => $column, 'message' => "Use yes or no, not \"{$value}\"."];
        }
    }
}
