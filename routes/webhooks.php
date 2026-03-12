<?php

use App\Http\Controllers\Mgmt\LemonSqueezyWebhookController;
use App\Http\Middleware\VerifyLemonSqueezyWebhook;

Route::post('lemonsqueezy', LemonSqueezyWebhookController::class)
    ->middleware(VerifyLemonSqueezyWebhook::class)
    ->name('lemonsqueezy');
