<?php

use App\Http\Controllers\Auth\BroadcastController;
use App\Http\Controllers\Auth\CreateOneTimeTokenController;
use App\Http\Controllers\Auth\DeleteTokenController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ImpersonationController;
use App\Http\Controllers\Auth\IssueTokenController;
use App\Http\Controllers\Auth\LoginOneTimeTokenController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Auth\TwoFactorBackupCodesController;
use App\Http\Controllers\Auth\TwoFactorDisableController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\Auth\TwoFactorStatusController;
use App\Http\Controllers\Auth\TwoFactorVerifyController;

Route::post('register', RegisterController::class)
    ->middleware(['throttle:login', 'stateful'])
    ->name('register');

Route::post('token', IssueTokenController::class)
    ->middleware(['throttle:login', 'stateful'])
    ->name('token.login');

Route::post('social/2fa', [SocialLoginController::class, 'verifyTwoFactor'])
    ->middleware(['throttle:login', 'stateful'])
    ->name('social.2fa');

Route::delete('token', DeleteTokenController::class)
    ->middleware(['auth:sanctum', 'stateful'])
    ->name('token.logout');

Route::post('logout', DeleteTokenController::class)
    ->middleware(['auth:sanctum', 'stateful'])
    ->name('logout');

Route::post('impersonate', [ImpersonationController::class, 'store'])
    ->middleware(['auth:sanctum'])
    ->name('impersonate.store');

Route::match(['get', 'post'], '/broadcast', BroadcastController::class)
    ->middleware(['auth:sanctum', 'stateful']);

Route::delete('impersonate', [ImpersonationController::class, 'destroy'])
    ->middleware(['auth:sanctum'])
    ->name('impersonate.destroy');

Route::post('password/email', ForgotPasswordController::class)
    ->middleware('throttle:login')
    ->name('password.email');

Route::post('password/reset', ResetPasswordController::class)
    ->middleware('throttle:login')
    ->name('password.reset');

Route::post('one-time-token/create', CreateOneTimeTokenController::class)
    ->middleware('throttle:login')
    ->name('one-time-token.create');

Route::post('one-time-token/login', LoginOneTimeTokenController::class)
    ->middleware('throttle:one-time')
    ->name('one-time-token.login');

Route::post('email/verify/send', [EmailVerificationController::class, 'send'])
    ->middleware(['auth:sanctum', 'throttle:login'])
    ->name('verification.send');

Route::group(['prefix' => '2fa'], function () {

    Route::get('status', TwoFactorStatusController::class)
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.verify');

    Route::post('setup', [TwoFactorSetupController::class, 'start'])
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.setup');

    Route::post('setup/confirm', [TwoFactorSetupController::class, 'confirm'])
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.setup.confirm');

    Route::post('verify', TwoFactorVerifyController::class)
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.verify');

    Route::post('disable', TwoFactorDisableController::class)
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.disable');

    Route::post('backup-codes/regenerate', [TwoFactorBackupCodesController::class, 'regenerate'])
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.backup-codes.regenerate');
});
