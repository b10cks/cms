<?php

use App\Http\Controllers\Mgmt\ConfigController;
use App\Http\Controllers\Mgmt\HealthController;

Route::get('health', HealthController::class)->name('health');
Route::get('config', ConfigController::class)->name('config');
