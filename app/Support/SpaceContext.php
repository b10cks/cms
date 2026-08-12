<?php

namespace App\Support;

use App\Models\Management\Space;
use Closure;

/**
 * Helper for temporarily binding the ambient `currentSpace` used by the
 * per-space DB resolver. Callers must restore the prior context afterwards so a
 * space binding can't leak into unrelated work on a long-lived worker or an
 * Octane request.
 *
 * Usage:
 *   $restore = SpaceContext::enter($space);
 *   try {
 *       // ... code that resolves space models ...
 *   } finally {
 *       $restore();
 *   }
 */
class SpaceContext
{
    /**
     * The currently bound space, or null when none is bound (e.g. on a queue
     * worker outside SpaceContext::enter()). Never throws.
     */
    public static function current(): ?Space
    {
        $space = app()->bound('currentSpace') ? app('currentSpace') : null;

        return $space instanceof Space ? $space : null;
    }

    /**
     * Bind the given space as the current one and return a callable that
     * restores whatever was bound before.
     */
    public static function enter(Space $space): Closure
    {
        $hadSpace = app()->bound('currentSpace');
        $priorSpace = $hadSpace ? app('currentSpace') : null;

        app()->offsetSet('currentSpace', $space);

        return static function () use ($hadSpace, $priorSpace): void {
            if ($hadSpace) {
                app()->offsetSet('currentSpace', $priorSpace);
            } else {
                app()->offsetUnset('currentSpace');
            }
        };
    }
}
