<?php

namespace App\Policies;

use App\Models\Management\AiRun;
use App\Models\Management\Space;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

/** Runs are private to their actor, who must still have access to the space. */
class AiRunPolicy
{
    use AuthorizesWithAbilities;

    /** Starting a run needs the edit right of the surface it acts on. */
    public const SURFACE_ABILITIES = [
        'asset' => 'assets.manage',
        'bulk:assets' => 'assets.manage',
        'bulk:data_entries' => 'data_entries.manage',
    ];

    public function create(User $user, Space $space, string $surface): bool
    {
        return $this->canInSpace($user, $space, self::SURFACE_ABILITIES[$surface] ?? 'content.manage');
    }

    /** Attaching files needs the right to start a run on any surface. */
    public function attach(User $user, Space $space): bool
    {
        return collect(['content.manage', 'assets.manage', 'data_entries.manage'])
            ->contains(fn (string $ability) => $this->canInSpace($user, $space, $ability));
    }

    public function view(User $user, AiRun $run, Space $space): bool
    {
        return $run->space_id === $space->id
            && $run->actor_id === $user->id
            && $this->canInSpace($user, $space, 'space.view');
    }
}
