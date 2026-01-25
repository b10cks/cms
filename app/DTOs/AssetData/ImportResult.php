<?php

namespace App\DTOs\AssetData;

class ImportResult
{
    public function __construct(
        public readonly array $successes = [],
        public readonly array $changes = [],
        public readonly array $ignoredFields = [],
        public readonly array $errors = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'successes' => $this->successes,
            'changes' => $this->changes,
            'ignored_fields' => array_unique($this->ignoredFields),
            'errors' => $this->errors,
            'summary' => [
                'total_success' => \count($this->successes),
                'total_changes' => \count($this->changes),
                'total_errors' => \count($this->errors),
            ],
        ];
    }
}
