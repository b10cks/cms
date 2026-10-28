<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Model;

/**
 * A model stored in the current space's database, without the automation
 * triggers of {@see SpaceModel}. For tenant data no automation targets, like
 * AI runs and threads, which save often and would pay for the snapshots.
 */
abstract class SpaceDatabaseModel extends Model
{
    /**
     * The grammar default for every supported space driver (MySQL, Postgres,
     * SQLite). Set here so datetime casts skip the connection lookup.
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    public function getConnection()
    {
        return app('App\Services\Database\SpaceModelResolver')->getDefaultConnection();
    }

    public function getConnectionName()
    {
        return app('App\Services\Database\SpaceModelResolver')->getConnectionName();
    }
}
