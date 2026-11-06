<?php

namespace App\Services\Ai\Knowledge\Sheet;

use App\Exceptions\GlossaryConceptConflict;
use App\Http\Requests\Ai\SaveAiGlossaryConceptRowRequest;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use App\Services\Ai\Knowledge\Concepts\GlossaryLocales;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use App\Services\System\AuditService;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Applies a sheet directly, one row per concept. Missing rows stay, blank cells keep
 * their value and nothing is removed. A bad row becomes a row error and the rest
 * applies. Each import writes one audit log entry with its actor, file name and counts.
 *
 * @phpstan-import-type Sheet from GlossarySheetReader
 * @phpstan-import-type ConceptInput from GlossaryConcepts
 */
final class GlossarySheetImport
{
    public const AUDIT_ACTION = 'glossary_sheet_imported';

    private const PROGRESS_EVERY = 50;

    /** @var array<string, array<int, mixed>> */
    private array $rules;

    /** @var list<array{row: int, column: string, message: string}> */
    private array $errors = [];

    /** @var array<string, int> row id => the file row that first applied to it */
    private array $claimed = [];

    /** @var array<string, true> */
    private array $usedLocales = [];

    private int $added = 0;

    private int $updated = 0;

    private int $unchanged = 0;

    /** @param  Sheet  $sheet */
    public function __construct(private readonly AiGlossary $glossary, private readonly UploadedFile $file, private array $sheet, private readonly ?User $user, private readonly AuditService $audit)
    {
        $this->rules = SaveAiGlossaryConceptRowRequest::conceptRules();
    }

    /** @return Generator<int, array{processed: int, total: int}, mixed, GlossarySheetImportResult> */
    public function run(): Generator
    {
        $space = $this->glossary->space_id !== null ? Space::query()->find($this->glossary->space_id) : null;
        $columns = new GlossarySheetColumns($this->sheet['headers'], new GlossaryLocales($space, []));

        if (! $columns->identifiesRows()) {
            throw ValidationException::withMessages(['file' => ['The first row must name the columns, with "concept" or at least one "term:<language>" or "avoid:<language>" column.']]);
        }

        $total = \count($this->sheet['rows']);
        $processed = 0;

        yield ['processed' => 0, 'total' => $total];

        $concepts = GlossaryConcepts::open($this->glossary);
        $defaultCode = $space?->settings->getDefaultLanguage() ?? ($columns->wordingLocales()[0] ?? null);
        $default = $defaultCode !== null ? GlossaryMatcher::normalizeLocale($defaultCode) : null;

        yield ['processed' => 0, 'total' => $total];

        foreach (array_keys($this->sheet['rows']) as $number) {
            $this->applyRow($number, $this->sheet['rows'][$number], $columns, $concepts, $default);
            unset($this->sheet['rows'][$number]);

            if (++$processed % self::PROGRESS_EVERY === 0) {
                yield ['processed' => $processed, 'total' => $total];
            }
        }

        if ($concepts->pendingWrites() > 0) {
            $this->commit($concepts);
        }

        $this->writeAuditEntry();

        yield ['processed' => $total, 'total' => $total];

        return new GlossarySheetImportResult(
            $this->added,
            $this->updated,
            $this->unchanged,
            $this->errors,
            $columns->unknown(),
            $this->warnings($space),
        );
    }

    /** @param  array<int, string>  $cells */
    private function applyRow(int $number, array $cells, GlossarySheetColumns $columns, GlossaryConcepts $concepts, ?string $default): void
    {
        if ($columns->hasExtraCells($cells)) {
            $this->fail($number, '', 'This row has more cells than the first row has columns. A value with a separator in it needs quotes.');

            return;
        }

        ['input' => $input, 'errors' => $errors] = $columns->input($cells);
        $rowId = $this->matchRow($input, $concepts, $default);

        foreach ($this->validationErrors($input, $columns) as $error) {
            $errors[] = $error;
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->fail($number, $error['column'], $error['message']);
            }

            return;
        }

        if ($rowId !== null && isset($this->claimed[$rowId])) {
            $this->failDuplicate($number, $input, $default, $this->claimed[$rowId]);

            return;
        }

        $before = $concepts->pendingWrites();

        try {
            $id = $rowId !== null ? $concepts->update($rowId, $input) : $concepts->create($input);
        } catch (GlossaryConceptConflict $conflict) {
            $this->fail($number, $this->conflictColumn($conflict, $columns), $this->conflictMessage($conflict));

            return;
        }

        $this->claimed[$rowId ?? $id] = $number;
        $this->claimed[$id] = $number;

        foreach (array_keys($input['languages'] ?? []) as $code) {
            $this->usedLocales[(string) $code] = true;
        }

        match (true) {
            $rowId === null => $this->added++,
            $concepts->pendingWrites() > $before => $this->updated++,
            default => $this->unchanged++,
        };
    }

    /**
     * The row this input means. A `concept` key decides first. Otherwise, and for a
     * new key, the default language's wording decides: its Use, or the first Avoid of a
     * ban. A row without that wording also matches on its other languages, because adding
     * a language to it moves nothing. A different key on the holder means another
     * concept, and the writer refuses it.
     *
     * @param  ConceptInput  $input
     */
    private function matchRow(array $input, GlossaryConcepts $concepts, ?string $default): ?string
    {
        $key = $input['key'] ?? null;

        if ($key !== null && ($id = $concepts->rowIdForKey($key)) !== null) {
            return $id;
        }

        $wordings = [];
        foreach ($input['languages'] ?? [] as $code => $values) {
            $wording = self::wordingOf($values);

            if ($wording !== null) {
                $wordings[GlossaryMatcher::normalizeLocale((string) $code)] = $wording;
            }
        }

        $holder = null;

        if ($default !== null && isset($wordings[$default])) {
            $holder = $concepts->rowIdForWording($default, $wordings[$default]);
        }

        if ($holder === null) {
            foreach ($wordings as $locale => $wording) {
                $id = $locale !== $default ? $concepts->rowIdForWording($locale, $wording) : null;

                if ($id !== null && (! isset($wordings[$default ?? '']) || ! isset($concepts->rowFacts($id)['terms'][$default ?? '']))) {
                    $holder = $id;

                    break;
                }
            }
        }

        if ($holder === null) {
            return null;
        }

        $holderKey = $key !== null ? $concepts->rowFacts($holder)['key'] : null;

        return $holderKey !== null && $holderKey !== $key ? null : $holder;
    }

    /** @param  array<string, mixed>  $values */
    private static function wordingOf(array $values): ?string
    {
        return $values['use'] ?? $values['avoid'][0] ?? null;
    }

    /**
     * @param  ConceptInput  $input
     * @return list<array{column: string, message: string}>
     */
    private function validationErrors(array $input, GlossarySheetColumns $columns): array
    {
        $validator = Validator::make($input, $this->rulesFor($input));

        if (! $validator->fails()) {
            return [];
        }

        $errors = [];

        foreach ($validator->errors()->messages() as $key => $messages) {
            $column = $columns->columnOf($key);
            $errors[$column] = [
                'column' => $column,
                'message' => $this->readableMessage($messages[0], $key, $column),
            ];
        }

        return array_values($errors);
    }

    /**
     * The rules for what this row holds, with their wildcards spelled out. Expanding
     * them is most of a validator's cost, and an import does it once per row.
     *
     * @param  ConceptInput  $input
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(array $input): array
    {
        $rules = [];

        foreach ($this->rules as $pattern => $rule) {
            foreach ($this->matchingKeys(explode('.', $pattern), $input) as $key) {
                $rules[$key] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param  list<string>  $segments
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function matchingKeys(array $segments, array $data): array
    {
        $segment = array_shift($segments);
        $keys = [];

        foreach ($segment === '*' ? array_keys($data) : (\array_key_exists($segment, $data) ? [$segment] : []) as $key) {
            if ($segments === []) {
                $keys[] = (string) $key;
            } elseif (\is_array($data[$key])) {
                foreach ($this->matchingKeys($segments, $data[$key]) as $rest) {
                    $keys[] = "{$key}.{$rest}";
                }
            }
        }

        return $keys;
    }

    /** Validation names the input key. The person knows the column. */
    private function readableMessage(string $message, string $key, string $column): string
    {
        return str_replace([$key, str_replace('_', ' ', $key)], $column, $message);
    }

    /** @param  ConceptInput  $input */
    private function failDuplicate(int $number, array $input, ?string $default, int $first): void
    {
        if (isset($input['key'])) {
            $this->fail($number, 'concept', "The concept {$input['key']} already appears in row {$first}. The first one applies.");

            return;
        }

        foreach ($input['languages'] ?? [] as $code => $values) {
            if (self::wordingOf($values) !== null && GlossaryMatcher::normalizeLocale((string) $code) === $default) {
                $column = isset($values['use']) ? 'term' : 'avoid';
                $this->fail($number, "{$column}:{$code}", 'The wording "'.self::wordingOf($values)."\" already appears in row {$first}. The first one applies.");

                return;
            }
        }

        $this->fail($number, '', "Row {$first} already changed this concept. The first one applies.");
    }

    private function conflictColumn(GlossaryConceptConflict $conflict, GlossarySheetColumns $columns): string
    {
        return match (true) {
            $conflict->reason === GlossaryConceptConflict::KEY_TAKEN => 'concept',
            $conflict->reason === GlossaryConceptConflict::NOTE_TOO_LONG => 'note',
            $conflict->reason === GlossaryConceptConflict::USE_AVOIDED => "avoid:{$conflict->details['locale']}",
            isset($conflict->details['locale']) => "term:{$conflict->details['locale']}",
            default => isset($columns->wordingLocales()[0]) ? "term:{$columns->wordingLocales()[0]}" : '',
        };
    }

    private function conflictMessage(GlossaryConceptConflict $conflict): string
    {
        $holder = $conflict->details['concept'] ?? null;

        return $holder !== null
            ? rtrim($conflict->getMessage(), '.').': '.($holder['key'] ?? $holder['term']).'.'
            : $conflict->getMessage();
    }

    private function fail(int $row, string $column, string $message): void
    {
        $this->errors[] = ['row' => $row, 'column' => $column, 'message' => $message];
    }

    private function commit(GlossaryConcepts $concepts): void
    {
        try {
            $concepts->commit();
        } catch (GlossaryConceptConflict $conflict) {
            throw ValidationException::withMessages(['file' => [$conflict->getMessage()]]);
        }
    }

    private function writeAuditEntry(): void
    {
        $this->audit->log(
            action: self::AUDIT_ACTION,
            entity: $this->glossary,
            newValues: ['added' => $this->added, 'updated' => $this->updated, 'unchanged' => $this->unchanged, 'errors' => \count($this->errors)],
            metadata: ['filename' => mb_substr($this->file->getClientOriginalName(), 0, 255)],
            user: $this->user,
        );
    }

    /**
     * @return list<array{code: string, locales: list<string>, message: string}>
     */
    private function warnings(?Space $space): array
    {
        $unused = array_column(array_filter(
            (new GlossaryLocales($space, array_map('strval', array_keys($this->usedLocales))))->available(),
            static fn (array $locale) => $locale['unused'],
        ), 'code');

        return $unused === [] ? [] : [[
            'code' => 'unused_locales',
            'locales' => array_values($unused),
            'message' => 'Stored, not used by this space: '.implode(', ', $unused).'.',
        ]];
    }
}
