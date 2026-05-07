<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppController extends Controller
{
    public function __invoke(Request $request)
    {
        $base = config('app.url');
        $requestHost = $request->getHost();
        $baseHost = parse_url($base, PHP_URL_HOST);

        if ($baseHost && $requestHost !== $baseHost) {
            return redirect($base, 301);
        }

        return view('app');
    }
}
