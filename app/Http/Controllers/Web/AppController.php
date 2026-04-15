<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppController extends Controller
{
    public function __invoke(Request $request)
    {
        $etag = '"' . config('app.version') . '"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', Response::HTTP_NOT_MODIFIED)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=604800, s-maxage=600, must-revalidate');
        }

        return response()
            ->view('app')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=604800, s-maxage=600, must-revalidate');
    }
}
