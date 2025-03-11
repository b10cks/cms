<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Model;

abstract class GlobalModel extends Model
{
    public function getConnectionName()
    {
        return config('database.default', 'default');
    }

    protected function newBaseQueryBuilder()
    {
        return $this->getConnection()->query();
    }
}
