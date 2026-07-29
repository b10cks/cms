<?php

namespace App\Services\Database;

use App\Models\Management\Space;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

class SpaceModelResolver implements ConnectionResolverInterface
{
    protected array $cache = [];

    public function connection($name = null)
    {
        return $this->getDefaultConnection();
    }

    public function getDefaultConnection()
    {
        if (app()->runningUnitTests()) {
            return app('db')->connection();
        }
        // Deliberately the *route* parameter: request('space') checks query and
        // body input before the route, so `?space=x` could displace the bound
        // model on the most safety-critical lookup in the app. Only an already
        // resolved Space counts; anything else falls back to the ambient
        // context (jobs, delivery API) or aborts.
        $space = request()->route('space');
        if (! $space instanceof Space) {
            $space = \App\Support\SpaceContext::current();
        }
        abort_unless(!!$space, 404, 'Space not found');

        $id = $space->id;
        if (!isset($this->cache[$id])) {
            $connection = $space->defaultConnection[0] ?? null;
            abort_unless(!!$connection, 404, 'Connection not found');
            $this->cache[$id] = app(ConnectionFactory::class)->make($connection);
        }

        return $this->cache[$id];
    }

    public function getConnectionName()
    {
        return $this->getDefaultConnection()?->getName();
    }

    public function setDefaultConnection($name)
    {

    }
}
