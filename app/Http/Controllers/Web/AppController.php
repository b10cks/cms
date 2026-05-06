<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppController extends Controller
{
    public function __invoke(Request $request)
    {
        $base = config('app.url');
        if (request()->getSchemeAndHttpHost() !== $base) {
            return redirect($base, 301);
        }

        return view('app');
    }
}
