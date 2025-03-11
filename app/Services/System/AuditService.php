<?php

namespace App\Services\System;

use App\Models\System\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AuditService
{
    public function log(
        string $action,
        Model  $entity,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?User  $user = null
    ): AuditLog
    {
        $metadata = array_merge([
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ], $metadata ?? []);

        return AuditLog::create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata
        ]);
    }

    public function logChanges(
        string $action,
        Model  $entity,
        array  $changes,
        ?array $metadata = null,
        ?User  $user = null
    ): AuditLog
    {
        return $this->log(
            action: $action,
            entity: $entity,
            oldValues: Arr::mapWithKeys($changes, fn ($k) => [$k => $entity->getOriginal($k)]),
            newValues: Arr::mapWithKeys($changes, fn ($k) => [$k => $entity->getAttribute($k)]),
            metadata: $metadata,
            user: $user
        );
    }
}
