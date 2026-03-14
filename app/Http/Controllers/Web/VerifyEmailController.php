<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    private const NOTIFICATION_PARAM = 'notification';
    private const EMAIL_VERIFIED_NOTIFICATION = 'email_verified';

    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        $routeName = auth()->check() ? 'home' : 'login';

        return redirect()->route($routeName, [
            self::NOTIFICATION_PARAM => self::EMAIL_VERIFIED_NOTIFICATION,
        ]);
    }
}
