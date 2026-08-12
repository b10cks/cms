<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Base policy for space-scoped resources whose abilities follow the standard
 * `<resource>.view` / `<resource>.manage` mapping with no per-model rules.
 * Concrete policies only declare their ability prefix; anything that needs
 * real per-model logic (ownership, tenancy checks) should not extend this.
 */
abstract class SpaceResourcePolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    /**
     * Ability prefix, e.g. `blocks` for `blocks.view` and `blocks.manage`.
     */
    protected string $resource;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, "{$this->resource}.view");
    }

    public function view(User $user, mixed $model, Space $space): bool
    {
        return $this->canInSpace($user, $space, "{$this->resource}.view");
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, "{$this->resource}.manage");
    }

    public function update(User $user, mixed $model, Space $space): bool
    {
        return $this->canInSpace($user, $space, "{$this->resource}.manage");
    }

    public function delete(User $user, mixed $model, Space $space): bool
    {
        return $this->canInSpace($user, $space, "{$this->resource}.manage");
    }
}
