<?php

use App\Http\Controllers\Mgmt\ConfigController;
use App\Http\Controllers\Mgmt\HealthController;
use App\Http\Controllers\Mgmt\PlanController;
use App\Http\Controllers\Mgmt\FieldPluginSandboxController;
use App\Http\Controllers\Mgmt\PublicAssetShareController;
use App\Http\Controllers\Mgmt\PublicInviteController;

Route::get('health', HealthController::class)->name('health');
Route::get('config', ConfigController::class)->name('config');
Route::get('plans', PlanController::class)
    ->middleware('cache.headers:public;max_age=3600;etag')
    ->name('plans.index');
Route::get('invites/{invite}', [PublicInviteController::class, 'show'])
    ->middleware('throttle:crucial')
    ->name('invites.show');

// Sandboxed field-plugin shell: the editor iframe has an opaque origin and
// cannot authenticate, so access is guarded by a permanent signed URL.
Route::get('spaces/{space}/field-plugins/{fieldPlugin}/sandbox', FieldPluginSandboxController::class)
    ->middleware(['signed', 'throttle:shares'])
    ->name('spaces.field-plugins.sandbox');

// Shares live in each space's own database — the space id in the URL is what
// resolves the right database before the token can even be looked up.
Route::prefix('shares/{space}/{token}')->name('shares.')->group(function () {
    Route::get('/', [PublicAssetShareController::class, 'show'])
        ->middleware('throttle:shares')
        ->name('show');
    Route::get('assets', [PublicAssetShareController::class, 'assets'])
        ->middleware('throttle:shares')
        ->name('assets');
    Route::post('unlock', [PublicAssetShareController::class, 'unlock'])
        ->middleware('throttle:share-unlock')
        ->name('unlock');
    Route::get('download', [PublicAssetShareController::class, 'download'])
        ->middleware('throttle:shares')
        ->name('download');
    Route::get('assets/{asset}/download', [PublicAssetShareController::class, 'downloadAsset'])
        ->middleware('throttle:shares')
        ->name('assets.download');
    // Higher limit than the JSON endpoints: a grid page loads many previews.
    Route::get('assets/{asset}/preview', [PublicAssetShareController::class, 'previewAsset'])
        ->middleware('throttle:share-previews')
        ->name('assets.preview');
});
