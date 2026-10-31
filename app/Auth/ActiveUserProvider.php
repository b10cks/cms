<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;

/**
 * The user provider behind every session, remember-me cookie and password
 * check. Suspended users are invisible to it, so a suspended account cannot
 * sign in and its existing sessions stop resolving to a user.
 */
class ActiveUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->whereNull('suspended_at');
    }
}
