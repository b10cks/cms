<?php

namespace App\DTOs\ImportExport;

class ImportResult
{
    public function __construct(
        public readonly array $successes = [],
        public readonly array $changes = [],
        public readonly array $ignoredFields = [],
        public readonly array $errors = [],
        public readonly array $deleted = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'successes' => $this->successes,
            'changes' => $this->changes,
            'ignored_fields' => array_values(array_unique($this->ignoredFields)),
            'errors' => $this->errors,
            'deleted' => $this->deleted,
            'summary' => [
                'total_success' => \count($this->successes),
                'total_changes' => \count($this->changes),
                'total_errors' => \count($this->errors),
                'total_deleted' => \count($this->deleted),
            ],
        ];
    }
}
