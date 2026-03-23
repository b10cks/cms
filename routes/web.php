<?php

use App\Http\Controllers\Web\AppController;
use App\Http\Controllers\Web\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// Root
Route::get('/', AppController::class)->name('home');

// Auth routes
Route::get('/login', AppController::class)->name('login');
Route::get('/login/signup', AppController::class)->name('login-signup');
Route::get('/login/password', AppController::class)->name('login-password');
Route::get('/login/password/request', AppController::class)->name('password.request');
Route::get('/login/password/reset', AppController::class)->name('password.reset');

// Email verification (special handling)
Route::get('auth/v1/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed'])
    ->name('verification.verify');

// Guest routes
Route::get('/invites/{id}', AppController::class)->name('invite');
Route::get('/invites/accept/{id}', AppController::class)->name('invite-accept');

// Space creation
Route::get('/spaces/new', AppController::class)->name('spaces-new');

// Teams
Route::get('/teams', AppController::class)->name('teams');
Route::get('/teams/{team}', AppController::class)->name('team');

// Account settings
Route::get('/account/settings', AppController::class)->name('account-settings');
Route::get('/account/settings/invites', AppController::class)->name('account-settings-invites');
Route::get('/account/settings/security', AppController::class)->name('account-settings-security');

// Space routes (must come last to avoid shadowing more specific routes)
Route::get('/{space}', AppController::class)->name('space');
Route::get('/{space}/content', AppController::class)->name('space-content');
Route::get('/{space}/canvas', AppController::class)->name('space-canvas');
Route::get('/{space}/content-wizard', AppController::class)->name('space-content-wizard');
Route::get('/{space}/content/{contentId}', AppController::class)->name('space-content-contentId');
Route::get('/{space}/content/{contentId}/localization', AppController::class)->name('space-content-contentId-localization');
Route::get('/{space}/content/{contentId}/versions', AppController::class)->name('space-content-contentId-versions');
Route::get('/{space}/assets', AppController::class)->name('space-assets');
Route::get('/{space}/blocks', AppController::class)->name('space-blocks');
Route::get('/{space}/blocks/{block}', AppController::class)->name('space-block');
Route::get('/{space}/datasources', AppController::class)->name('space-datasources');
Route::get('/{space}/datasources/{dataSourceId}', AppController::class)->name('space-datasources-dataSourceId');
Route::get('/{space}/releases', AppController::class)->name('space-releases');
Route::get('/{space}/redirects', AppController::class)->name('space-redirects');
Route::get('/{space}/settings', AppController::class)->name('space-settings');
Route::get('/{space}/settings/configuration', AppController::class)->name('space-settings-configuration');
Route::get('/{space}/settings/ai', AppController::class)->name('space-settings-ai');
Route::get('/{space}/settings/people', AppController::class)->name('space-settings-people');
Route::get('/{space}/settings/backups', AppController::class)->name('space-settings-backups');
Route::get('/{space}/settings/migrations', AppController::class)->name('space-settings-migrations');
Route::get('/{space}/settings/subscription', AppController::class)->name('space-settings-subscription');
