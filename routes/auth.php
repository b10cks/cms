<?php

use App\Http\Controllers\Auth\BroadcastController;

Route::post('register', \App\Http\Controllers\Auth\RegisterController::class)
    ->middleware(['throttle:login', 'stateful'])
    ->name('register');

Route::post('token', \App\Http\Controllers\Auth\IssueTokenController::class)
    ->middleware(['throttle:login', 'stateful'])
    ->name('token.login');

Route::delete('token', \App\Http\Controllers\Auth\DeleteTokenController::class)
    ->middleware(['auth:sanctum', 'stateful'])
    ->name('token.logout');

Route::post('impersonate', [\App\Http\Controllers\Auth\ImpersonationController::class, 'store'])
    ->middleware(['auth:sanctum'])
    ->name('impersonate.store');

Route::match(['get', 'post'], '/broadcast', BroadcastController::class)
    ->middleware(['auth:sanctum', 'stateful']);

Route::delete('impersonate', [\App\Http\Controllers\Auth\ImpersonationController::class, 'destroy'])
    ->middleware(['auth:sanctum'])
    ->name('impersonate.destroy');

Route::post('password/email', \App\Http\Controllers\Auth\ForgotPasswordController::class)
    ->middleware('throttle:login')
    ->name('password.email');

Route::post('password/reset', \App\Http\Controllers\Auth\ResetPasswordController::class)
    ->middleware('throttle:login')
    ->name('password.reset');

Route::post('one-time-token/create', \App\Http\Controllers\Auth\CreateOneTimeTokenController::class)
    ->middleware('throttle:login')
    ->name('one-time-token.create');

Route::post('one-time-token/login', \App\Http\Controllers\Auth\LoginOneTimeTokenController::class)
    ->name('one-time-token.login');

Route::post('email/verify/send', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'send'])
    ->middleware(['auth:sanctum', 'throttle:login'])
    ->name('verification.send');

Route::post('email/verify', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'verify'])
    ->middleware(['throttle:login'])
    ->name('verification.verify');

Route::group(['prefix' => '2fa'], function () {

    Route::get('status', \App\Http\Controllers\Auth\TwoFactorStatusController::class)
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.verify');

    Route::post('setup', [\App\Http\Controllers\Auth\TwoFactorSetupController::class, 'start'])
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.setup');

    Route::post('setup/confirm', [\App\Http\Controllers\Auth\TwoFactorSetupController::class, 'confirm'])
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.setup.confirm');

    Route::post('verify', \App\Http\Controllers\Auth\TwoFactorVerifyController::class)
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.verify');

    Route::post('disable', \App\Http\Controllers\Auth\TwoFactorDisableController::class)
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.disable');

    Route::post('backup-codes/regenerate', [\App\Http\Controllers\Auth\TwoFactorBackupCodesController::class, 'regenerate'])
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('2fa.backup-codes.regenerate');
});
