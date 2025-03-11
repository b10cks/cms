<?php

namespace App\Policies;

use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DataEntryPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any data entries.
     *
     * @param User $user
     * @param DataSource $dataSource
     * @param Space $space
     * @return bool
     */
    public function viewAny(User $user, DataSource $dataSource, Space $space): bool
    {
        // Users can view entries if they have access to the space
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can view the data entry.
     *
     * @param User $user
     * @param DataEntry $entry
     * @param DataSource $dataSource
     * @param Space $space
     * @return bool
     */
    public function view(User $user, DataEntry $entry, DataSource $dataSource, Space $space): bool
    {
        // Verify the entry belongs to the data source
        if ($entry->data_source_id !== $dataSource->id) {
            return false;
        }

        // Users can view entries if they have access to the space
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can create data entries.
     *
     * @param User $user
     * @param DataSource $dataSource
     * @param Space $space
     * @return bool
     */
    public function create(User $user, DataSource $dataSource, Space $space): bool
    {
        // Only owners, admins and editors can create entries
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin', 'editor'])
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can update the data entry.
     *
     * @param User $user
     * @param DataEntry $entry
     * @param DataSource $dataSource
     * @param Space $space
     * @return bool
     */
    public function update(User $user, DataEntry $entry, DataSource $dataSource, Space $space): bool
    {
        // Verify the entry belongs to the data source
        if ($entry->data_source_id !== $dataSource->id) {
            return false;
        }

        // Only owners, admins and editors can update entries
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin', 'editor'])
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can delete the data entry.
     *
     * @param User $user
     * @param DataEntry $entry
     * @param DataSource $dataSource
     * @param Space $space
     * @return bool
     */
    public function delete(User $user, DataEntry $entry, DataSource $dataSource, Space $space): bool
    {
        // Verify the entry belongs to the data source
        if ($entry->data_source_id !== $dataSource->id) {
            return false;
        }

        // Only owners, admins and editors can delete entries
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin', 'editor'])
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can perform bulk operations on data entries.
     *
     * @param User $user
     * @param DataSource $dataSource
     * @param Space $space
     * @return bool
     */
    public function bulkOperation(User $user, DataSource $dataSource, Space $space): bool
    {
        // Only owners, admins and editors can perform bulk operations
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin', 'editor'])
                ->exists() || $user->is_root;
    }
}
