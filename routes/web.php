<?php

use App\Http\Controllers\Auth\SamlLoginController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Web\AppController;
use App\Http\Controllers\Web\DocsController;
use App\Http\Controllers\Web\TransferDownloadController;
use App\Http\Controllers\Web\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// Root
Route::get('/', AppController::class)->name('home');

// Streams package/backup downloads when the transfers disk is local
// (self-hosted without S3); links are short-lived signed URLs.
Route::get('/transfers/download', TransferDownloadController::class)
    ->middleware(['signed', 'throttle:shares'])
    ->name('transfers.download');

// Auth routes
Route::get('/login', AppController::class)->name('login');
Route::get('/login/signup', AppController::class)->name('login-signup');
Route::get('/login/password', AppController::class)->name('login-password');
Route::get('/login/password/request', AppController::class)->name('password.request');
Route::get('/login/password/reset', AppController::class)->name('password.reset');
Route::get('/auth/v1/social/{provider}/redirect', [SocialLoginController::class, 'redirect'])
    ->middleware('throttle:login')
    ->name('auth.social.redirect');
Route::get('/auth/v1/social/{provider}/callback', [SocialLoginController::class, 'callback'])
    ->middleware('throttle:login')
    ->name('auth.social.callback');
Route::get('/auth/v1/social/{provider}/link', [SocialLoginController::class, 'linkRedirect'])
    ->middleware(['auth', 'throttle:login'])
    ->name('auth.social.link.redirect');
Route::get('/auth/v1/social/{provider}/link/callback', [SocialLoginController::class, 'linkCallback'])
    ->middleware(['auth', 'throttle:login'])
    ->name('auth.social.link.callback');
Route::get('/auth/v1/saml/{team}/redirect', [SamlLoginController::class, 'redirect'])
    ->middleware('throttle:login')
    ->name('auth.saml.redirect');
Route::post('/auth/v1/saml/{team}/acs', [SamlLoginController::class, 'acs'])
    ->middleware('throttle:login')
    ->name('auth.saml.acs');
Route::match(['get', 'post'], '/auth/v1/saml/{team}/sls', [SamlLoginController::class, 'sls'])
    ->middleware('throttle:login')
    ->name('auth.saml.sls');
Route::get('/auth/v1/saml/{team}/metadata', [SamlLoginController::class, 'metadata'])
    ->middleware('throttle:login')
    ->name('auth.saml.metadata');

// Email verification (special handling)
Route::get('auth/v1/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed'])
    ->name('verification.verify');

// Guest routes
Route::get('/invites/{id}', AppController::class)->name('invite');
Route::get('/invites/accept/{id}', AppController::class)->name('invite-accept');
Route::get('/share/{space}/{token}', AppController::class)->name('public-share');

// Documentation (static VitePress build; must precede the /{space} catch-all)
Route::get('/docs/{path?}', DocsController::class)
    ->where('path', '.*')
    ->name('docs');

// Space creation
Route::get('/spaces/new', AppController::class)->name('spaces-new');

// Teams
Route::get('/teams', AppController::class)->name('teams');
Route::get('/teams/{team}', AppController::class)->name('team');

// Account settings
Route::get('/account/settings', AppController::class)->name('account-settings');
Route::get('/account/settings/invites', AppController::class)->name('account-settings-invites');
Route::get('/account/settings/security', AppController::class)->name('account-settings-security');

// Provider routes
Route::get('/provider', AppController::class)->name('provider-dashboard');
Route::get('/provider/notes', AppController::class)->name('provider-notes');

// Space routes (must come last to avoid shadowing more specific routes)
Route::get('/{space}', AppController::class)->name('space');
Route::get('/{space}/onboarding', AppController::class)->name('space-onboarding');
Route::get('/{space}/content', AppController::class)->name('space-content');
Route::get('/{space}/canvas', AppController::class)->name('space-canvas');
Route::get('/{space}/content-wizard', AppController::class)->name('space-content-wizard');
Route::get('/{space}/content/{contentId}', AppController::class)->name('space-content-contentId');
Route::get('/{space}/content/{contentId}/localization', AppController::class)->name('space-content-contentId-localization');
Route::get('/{space}/content/{contentId}/versions', AppController::class)->name('space-content-contentId-versions');
Route::get('/{space}/assets', AppController::class)->name('space-assets');
Route::get('/{space}/blocks', AppController::class)->name('space-blocks');
Route::get('/{space}/icons', AppController::class)->name('space-icons');
Route::get('/{space}/blocks/{block}', AppController::class)->name('space-block');
Route::get('/{space}/datasources', AppController::class)->name('space-datasources');
Route::get('/{space}/datasources/{dataSourceId}', AppController::class)->name('space-datasources-dataSourceId');
Route::get('/{space}/releases', AppController::class)->name('space-releases');
Route::get('/{space}/audit-logs', AppController::class)->name('space-audit-logs');
Route::get('/{space}/automations', AppController::class)->name('space-automations');
Route::get('/{space}/automations/actions', AppController::class)->name('space-automations-actions');
Route::get('/{space}/automations/automations', AppController::class)->name('space-automations-automations');
Route::get('/{space}/automations/executions', AppController::class)->name('space-automations-executions');
Route::get('/{space}/redirects', AppController::class)->name('space-redirects');
Route::get('/{space}/settings', AppController::class)->name('space-settings');
Route::get('/{space}/settings/configuration', AppController::class)->name('space-settings-configuration');
Route::get('/{space}/settings/ai', AppController::class)->name('space-settings-ai');
Route::get('/{space}/settings/people', AppController::class)->name('space-settings-people');
Route::get('/{space}/settings/backups', AppController::class)->name('space-settings-backups');
Route::get('/{space}/settings/migrations', AppController::class)->name('space-settings-migrations');
Route::get('/{space}/settings/subscription', AppController::class)->name('space-settings-subscription');
Route::get('/{space}/settings/usage', AppController::class)->name('space-settings-usage');
Route::get('/{space}/settings/shares', AppController::class)->name('space-settings-shares');
Route::get('/{space}/settings/plugins', AppController::class)->name('space-settings-plugins');
