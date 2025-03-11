<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserAvatarRequest;
use App\Http\Resources\User\OwnUserResource;
use App\Services\Image\ImageUploadService;

class UserAvatarController extends Controller
{
    public function __invoke(UpdateUserAvatarRequest $request, ImageUploadService $uploadService)
    {
        $user = $request->user();
        $path = $uploadService->uploadForModel(
            $user,
            $request->file('avatar'),
            'avatar',
            'users/avatars'
        );

        return new OwnUserResource($user->fresh());
    }
}
