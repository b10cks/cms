<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\User\OwnUserResource;

class UserController extends Controller
{
    public function show()
    {
        return new OwnUserResource(auth()->user());
    }

    public function update(UpdateUserRequest $request)
    {
        $user = auth()->user();
        $user->forceFill($request->only(['firstname', 'lastname']));

        abort_unless($user->save(), 500);

        return new OwnUserResource($user);
    }
}
