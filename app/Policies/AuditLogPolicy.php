<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\AuditLog;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class AuditLogPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'audit_logs.view');
    }

    public function view(User $user, AuditLog $auditLog, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'audit_logs.view');
    }
}
