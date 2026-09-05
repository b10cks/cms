<?php

namespace App\Services\Database;

use App\Models\Management\Space;
use App\Support\SpaceContext;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SpaceModelResolver implements ConnectionResolverInterface
{
    protected ?string $activeConnectionId = null;

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
            $space = SpaceContext::current();
        }
        abort_unless((bool) $space, 404, 'Space not found');

        $connection = $space->defaultConnection[0] ?? null;
        abort_unless((bool) $connection, 404, 'Connection not found');

        if ($this->activeConnectionId !== null && $this->activeConnectionId !== $connection->id) {
            DB::purge($this->activeConnectionId);
            $connections = Config::get('database.connections', []);
            unset($connections[$this->activeConnectionId]);
            Config::set('database.connections', $connections);
        }

        $this->activeConnectionId = $connection->id;

        return app(ConnectionFactory::class)->make($connection);
    }

    public function getConnectionName()
    {
        return $this->getDefaultConnection()?->getName();
    }

    public function setDefaultConnection($name) {}
}
