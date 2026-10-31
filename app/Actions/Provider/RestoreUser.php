<?php

namespace App\Actions\Provider;

use App\Enums\InstanceAuditAction;
use App\Models\User;
use App\Services\Audit\InstanceAuditService;

/** Lifts a suspension. Sessions and tokens ended by it stay ended. */
class RestoreUser
{
    public function __construct(private readonly InstanceAuditService $audit) {}

    public function execute(User $actor, User $user): void
    {
        $reason = $user->suspension_reason;

        $user->forceFill([
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ])->save();

        $this->audit->record(InstanceAuditAction::UserRestored, $user, $actor, [
            'user_email' => $user->email,
            'suspension_reason' => $reason,
        ]);
    }
}
