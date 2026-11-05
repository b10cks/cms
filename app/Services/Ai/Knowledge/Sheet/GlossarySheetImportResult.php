<?php

namespace App\Services\Ai\Knowledge\Sheet;

use App\DTOs\ImportExport\ImportResult;

/**
 * What a sheet import did, counted in rows. Unknown columns report as ignored fields.
 */
final class GlossarySheetImportResult extends ImportResult
{
    /**
     * @param  list<array{row: int, column: string, message: string}>  $errors
     * @param  list<string>  $unknownColumns
     * @param  list<array{code: string, locales: list<string>, message: string}>  $warnings
     */
    public function __construct(
        public readonly int $added,
        public readonly int $updated,
        public readonly int $unchanged,
        array $errors,
        array $unknownColumns,
        public readonly array $warnings,
    ) {
        parent::__construct(ignoredFields: $unknownColumns, errors: $errors);
    }

    public function toArray(): array
    {
        $result = parent::toArray();

        return [
            ...$result,
            'warnings' => $this->warnings,
            'summary' => [
                ...$result['summary'],
                'total_success' => $this->added + $this->updated + $this->unchanged,
                'total_changes' => $this->added + $this->updated,
                'added' => $this->added,
                'updated' => $this->updated,
                'unchanged' => $this->unchanged,
            ],
        ];
    }
}
