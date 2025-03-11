<?php

Route::post('token', \App\Http\Controllers\Auth\IssueTokenController::class)
    ->middleware('throttle:login')
    ->name('token.login');

Route::delete('token', \App\Http\Controllers\Auth\DeleteTokenController::class)
    ->middleware(['auth:api'])
    ->name('token.logout');

Route::post('token/refresh', \App\Http\Controllers\Auth\RefreshTokenController::class)
    ->name('token.refresh');

Route::post('impersonate', [\App\Http\Controllers\Auth\ImpersonationController::class, 'store'])
    ->middleware(['auth:api'])
    ->name('impersonate.store');

Route::delete('impersonate', [\App\Http\Controllers\Auth\ImpersonationController::class, 'destroy'])
    ->middleware(['auth:api'])
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
