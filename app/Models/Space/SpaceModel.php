<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Model;

abstract class SpaceModel extends Model
{
    public function getConnection()
    {
        return app('App\Services\Database\SpaceModelResolver')->getDefaultConnection();
    }

    public function getConnectionName()
    {
        return app('App\Services\Database\SpaceModelResolver')->getConnectionName();
    }
}
