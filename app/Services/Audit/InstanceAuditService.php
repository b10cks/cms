<?php

namespace App\Services\Audit;

use App\Enums\InstanceAuditAction;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Database\Eloquent\Model;

/** Writes the instance audit stream: root actions in the management audit log. */
class InstanceAuditService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param  array<string, mixed>  $metadata */
    public function record(InstanceAuditAction $action, Model $entity, User $actor, array $metadata = []): void
    {
        $this->audit->log(
            action: $action->value,
            entity: $entity,
            metadata: $metadata,
            user: $actor,
        );
    }
}
