<?php

use App\Http\Controllers\PassThroughController;
use App\Http\Requests\User\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;

Route::get('/login', PassThroughController::class)->name('login');
Route::get('/password/reset', PassThroughController::class)->name('password.request');
Route::get('/password/reset/{token}', PassThroughController::class)->name('password.reset');

Route::get('auth/v1/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill();
    return redirect(route('login'));
})->middleware(['signed'])->name('verification.verify');
