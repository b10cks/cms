<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Broadcast;

class BroadcastController extends Controller
{
    public function __invoke(Request $request)
    {
        return Broadcast::auth($request);
    }
}
