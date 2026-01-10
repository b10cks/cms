<?php

namespace App\Services\Database;

use App\Models\Management\Space;
use Illuminate\Database\ConnectionResolverInterface;

class SpaceModelResolver implements ConnectionResolverInterface
{
    protected $caches = [];

    public function connection($name = null)
    {
        return $this->getDefaultConnection();
    }

    public function getDefaultConnection()
    {
        if (app()->runningUnitTests()) {
            return app('db')->connection();
        }

        /** @var Space|null $space */
        $space = request()->route('space');
        abort_unless($space, 404, 'Space not found');

        if (!isset($this->caches[$space->id])) {
            $connection = $space->defaultConnection[0] ?? null;
            abort_unless($connection, 404, 'Connection not found');
            $this->caches[$space->id] = app(ConnectionFactory::class)->make($connection);
        }

        return $this->caches[$space->id];
    }

    public function getConnectionName()
    {
        return $this->getDefaultConnection()->getName();
    }

    public function setDefaultConnection($name)
    {

    }
}
