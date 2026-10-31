<?php

namespace App\Services\Auth;

use App\Enums\InstanceAuditAction;
use App\Models\Management\RootElevation;
use App\Models\User;
use App\Services\Audit\InstanceAuditService;

/**
 * Starts and ends root elevations. Nothing is cached: every check reads the
 * running elevation ({@see User::activeElevation()}), so a start or end takes
 * effect with the next request and an expiry with the next check.
 */
class RootElevationService
{
    public function __construct(private readonly InstanceAuditService $audit) {}

    /** Replaces a running elevation, so there is never more than one. */
    public function start(User $user, string $reason, int $minutes): RootElevation
    {
        $this->end($user);

        $elevation = $user->elevations()->create([
            'reason' => $reason,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $user->forgetElevation();

        $this->audit->record(InstanceAuditAction::ElevationStarted, $elevation, $user, [
            'reason' => $reason,
            'minutes' => $minutes,
            'expires_at' => $elevation->expires_at->toIso8601String(),
        ]);

        return $elevation;
    }

    public function end(User $user): ?RootElevation
    {
        $elevation = $user->activeElevation();
        if ($elevation === null) {
            return null;
        }

        $elevation->forceFill(['ended_at' => now()])->save();
        $user->forgetElevation();

        $this->audit->record(InstanceAuditAction::ElevationEnded, $elevation, $user, [
            'reason' => $elevation->reason,
        ]);

        return $elevation;
    }

    /**
     * @return array{is_root: bool, is_elevated: bool, elevated_until: string|null, elevation_reason: string|null}
     */
    public function state(User $user): array
    {
        $elevation = $user->activeElevation();

        return [
            'is_root' => (bool) $user->is_root,
            'is_elevated' => $elevation !== null,
            'elevated_until' => $elevation?->expires_at->toIso8601String(),
            'elevation_reason' => $elevation?->reason,
        ];
    }
}
