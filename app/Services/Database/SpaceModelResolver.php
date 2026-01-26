<?php

namespace App\Services\Database;

use App\Models\Management\Space;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

class SpaceModelResolver implements ConnectionResolverInterface
{
    protected ConnectionInterface $cache;

    public function connection($name = null)
    {
        return $this->getDefaultConnection();
    }

    public function getDefaultConnection()
    {
        if (app()->runningUnitTests()) {
            return app('db')->connection();
        }

        if (!isset($this->cache)) {
            /** @var Space|null $space */
            $space = request('space') ?? app()->get('currentSpace');
            abort_unless(!!$space, 404, 'Space not found');

            $connection = $space->defaultConnection[0] ?? null;
            abort_unless(!!$connection, 404, 'Connection not found');
            $this->cache = app(ConnectionFactory::class)->make($connection);
        }

        return $this->cache;
    }

    public function getConnectionName()
    {
        return $this->getDefaultConnection()?->getName();
    }

    public function setDefaultConnection($name)
    {

    }
}
