<?php

namespace App\Enums;

/**
 * Root actions written to the management `audit_logs` table. The shared
 * `root.` prefix is what makes up the instance audit stream.
 */
enum InstanceAuditAction: string
{
    case ElevationStarted = 'root.elevation.started';
    case ElevationEnded = 'root.elevation.ended';
    case ImpersonationStarted = 'root.impersonation.started';
    case ImpersonationEnded = 'root.impersonation.ended';
    case UserSuspended = 'root.user.suspended';
    case UserRestored = 'root.user.restored';
    case UserSignedOut = 'root.user.signed_out';
    case OwnershipTransferred = 'root.team.ownership_transferred';

    public const PREFIX = 'root.';
}
