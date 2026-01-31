<?php

namespace App\Http\Controllers\Broadcasting;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Broadcast;

class JwtBroadcastController extends Controller
{
    /**
     * Authenticate the request for channel access.
     *
     * @return \Illuminate\Http\Response
     */
    public function authenticate(Request $request)
    {
        return Broadcast::auth($request);
    }
}
