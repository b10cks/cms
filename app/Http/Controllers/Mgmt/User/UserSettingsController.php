<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserSettingsRequest;

class UserSettingsController extends Controller
{
    public function __invoke(UpdateUserSettingsRequest $request)
    {
        $user = $request->user();
        $user->settings->apply($request->validated());
        abort_unless($user->save(), 500);

        return response()->noContent();
    }
}
