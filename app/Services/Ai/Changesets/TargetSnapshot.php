<?php

namespace App\Services\Ai\Changesets;

use Illuminate\Database\Eloquent\Model;

/** One target as a run or an accept sees it: its editable document and the version it came from. */
final readonly class TargetSnapshot
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public Model $model,
        public string $label,
        public array $document,
        public ?string $versionId = null,
    ) {}
}
