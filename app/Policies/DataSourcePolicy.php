<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class DataSourcePolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_sources.view');
    }

    public function view(User $user, DataSource $dataSource, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_sources.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_sources.manage');
    }

    public function update(User $user, DataSource $dataSource, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_sources.manage');
    }

    public function delete(User $user, DataSource $dataSource, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_sources.manage');
    }
}
