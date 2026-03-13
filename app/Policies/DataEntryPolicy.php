<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class DataEntryPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any data entries.
     */
    public function viewAny(User $user, DataSource $dataSource, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_entries.view');
    }

    /**
     * Determine whether the user can view the data entry.
     */
    public function view(User $user, DataEntry $entry, DataSource $dataSource, Space $space): bool
    {
        // Verify the entry belongs to the data source
        if ($entry->data_source_id !== $dataSource->id) {
            return false;
        }

        return $this->canInSpace($user, $space, 'data_entries.view');
    }

    /**
     * Determine whether the user can create data entries.
     */
    public function create(User $user, DataSource $dataSource, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_entries.manage');
    }

    /**
     * Determine whether the user can update the data entry.
     */
    public function update(User $user, DataEntry $entry, DataSource $dataSource, Space $space): bool
    {
        // Verify the entry belongs to the data source
        if ($entry->data_source_id !== $dataSource->id) {
            return false;
        }

        return $this->canInSpace($user, $space, 'data_entries.manage');
    }

    /**
     * Determine whether the user can delete the data entry.
     */
    public function delete(User $user, DataEntry $entry, DataSource $dataSource, Space $space): bool
    {
        // Verify the entry belongs to the data source
        if ($entry->data_source_id !== $dataSource->id) {
            return false;
        }

        return $this->canInSpace($user, $space, 'data_entries.manage');
    }

    /**
     * Determine whether the user can perform bulk operations on data entries.
     */
    public function bulkOperation(User $user, DataSource $dataSource, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'data_entries.manage');
    }
}
