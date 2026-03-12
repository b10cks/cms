<?php

use App\Http\Controllers\Mgmt\ConfigController;
use App\Http\Controllers\Mgmt\HealthController;
use App\Http\Controllers\Mgmt\PlanController;
use App\Http\Controllers\Mgmt\PublicInviteController;

Route::get('health', HealthController::class)->name('health');
Route::get('config', ConfigController::class)->name('config');
Route::get('plans', PlanController::class)
    ->middleware('cache.headers:public;max_age=3600;etag')
    ->name('plans.index');
Route::get('invites/{invite}', [PublicInviteController::class, 'show'])
    ->middleware('throttle:crucial')
    ->name('invites.show');
