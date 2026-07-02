<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Resolve a bounded per-page value from the request so a client can't force
     * an unbounded query with e.g. ?per_page=1000000.
     */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        $value = (int) $request->input('per_page', $default);

        return max(1, min($value, $max));
    }
}
