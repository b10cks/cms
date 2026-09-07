<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Laravel builds a controller while it gathers that controller's middleware,
 * which happens *before* the middleware pipeline runs. On the delivery API the
 * space is bound by `auth.data`, so anything a delivery controller pulls from
 * the container in its constructor is resolved with no space bound.
 *
 * A controller that injects a space-dependent service therefore 500s on every
 * request, valid token or not. This guards the whole delivery surface against
 * that, because it is invisible in a normal feature test: those bind
 * `currentSpace` in setUp and never see the pre-middleware window.
 */
class DeliveryControllerConstructionTest extends TestCase
{
    #[Test]
    public function delivery_controllers_build_without_a_bound_space(): void
    {
        app()->offsetUnset('currentSpace');

        $classes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->map(fn (RoutingRoute $route): ?string => $route->getControllerClass())
            ->filter()
            ->unique()
            ->values();

        $this->assertNotEmpty($classes, 'No delivery routes found — the route prefix probably moved.');

        foreach ($classes as $class) {
            try {
                app()->make($class);
            } catch (Throwable $e) {
                $this->fail("{$class} cannot be constructed before the space middleware runs: {$e->getMessage()}");
            }
        }
    }
}
