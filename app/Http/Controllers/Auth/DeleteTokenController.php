<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

class DeleteTokenController extends Controller
{
    public function __invoke()
    {
        auth()->logout();

        return response()->json(null, 204);
    }
}
