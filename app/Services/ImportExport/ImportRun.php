<?php

namespace App\Services\ImportExport;

use App\DTOs\ImportExport\ImportResult;
use Generator;

/**
 * Importers are generators: they yield `{processed, total}` between chunks and
 * return their result, so a stream can report progress and keep bytes flowing
 * past the CDN's origin read timeout. Nothing runs until they are iterated, and
 * they must never yield inside a transaction or a muted broadcast callback.
 */
final class ImportRun
{
    /** @param  Generator<int, array{processed: int, total: int}, mixed, ImportResult>  $run */
    public static function drain(Generator $run): ImportResult
    {
        foreach ($run as $_) {
            // Progress only matters to streaming callers.
        }

        return $run->getReturn();
    }
}
