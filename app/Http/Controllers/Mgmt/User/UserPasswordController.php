<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Actions\User\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ChangePasswordRequest;
use Hash;

class UserPasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, ChangePassword $changePassword)
    {
        if (!Hash::check($request->old_password, $request->user()->password)) {
            return response([
                'errors' => [
                    'old_password' => [__('errors.oldPasswordDoesNotMatch')],
                ],
            ], 403);
        }

        $changePassword->execute($request->user(), $request->password);

        return response()->noContent();
    }
}
