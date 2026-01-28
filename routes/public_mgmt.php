<?php

use App\Http\Controllers\Mgmt\ConfigController;
use App\Http\Controllers\Mgmt\HealthController;
use App\Http\Controllers\Mgmt\PublicInviteController;

Route::get('health', HealthController::class)->name('health');
Route::get('config', ConfigController::class)->name('config');
Route::get('invites/{token}', [PublicInviteController::class, 'show'])
    ->middleware('throttle:crucial')
    ->name('invites.show');
