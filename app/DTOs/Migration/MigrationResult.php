<?php

namespace App\DTOs\Migration;

class MigrationResult
{
    private array $created = [];
    private array $updated = [];
    private array $skipped = [];
    private array $errors = [];

    public function incrementCreated(string $entity): void
    {
        $this->created[$entity] = ($this->created[$entity] ?? 0) + 1;
    }

    public function incrementUpdated(string $entity): void
    {
        $this->updated[$entity] = ($this->updated[$entity] ?? 0) + 1;
    }

    public function incrementSkipped(string $entity): void
    {
        $this->skipped[$entity] = ($this->skipped[$entity] ?? 0) + 1;
    }

    public function addError(string $entity, string $id, string $message): void
    {
        $this->errors[] = [
            'entity' => $entity,
            'id' => $id,
            'message' => $message,
        ];
    }

    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
