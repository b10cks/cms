<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserSettingsController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $user->settings->apply($request->all());
        abort_unless($user->save(), 500);

        return response()->noContent();
    }
}
