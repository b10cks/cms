<?php

namespace App\Services\Database;

use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Support\SpaceContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use WeakMap;

/**
 * Connection resolver for every SpaceModel. Warmed under Octane
 * (config/octane.php), so its state is per worker. It never holds the current
 * space: that is read from the route or `currentSpace` (flushed per request)
 * on every call.
 */
class SpaceModelResolver implements ConnectionResolverInterface
{
    /**
     * The tenant connection this worker holds open. Switching to another one
     * releases it, so a worker never piles up idle tenant connections.
     */
    protected ?string $activeConnectionId = null;

    /**
     * Resolved connections for the active connection id, keyed by the loaded
     * SpaceConnection. Eloquent asks for the connection per hydrated row, so
     * this skips decrypting the config each time, while a freshly loaded
     * SpaceConnection (next request, rotated credentials) resolves again.
     *
     * @var WeakMap<SpaceConnection, ConnectionInterface>
     */
    protected WeakMap $resolved;

    public function __construct()
    {
        $this->resolved = new WeakMap();
    }

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

        return $this->resolved[$connection] ?? $this->resolve($connection);
    }

    public function getConnectionName()
    {
        return $this->getDefaultConnection()?->getName();
    }

    public function setDefaultConnection($name) {}

    private function resolve(SpaceConnection $connection): ConnectionInterface
    {
        $factory = app(ConnectionFactory::class);

        if ($this->activeConnectionId !== $connection->id) {
            if ($this->activeConnectionId !== null) {
                $factory->release($this->activeConnectionId);
            }
            $this->activeConnectionId = $connection->id;
            $this->resolved = new WeakMap();
        }

        return $this->resolved[$connection] = $factory->make($connection);
    }
}
