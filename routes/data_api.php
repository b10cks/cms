<?php

use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DataEntryController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\RedirectController;
use App\Http\Controllers\Api\RedirectLookupController;
use App\Http\Controllers\Api\SpaceController;

Route::get('contents', [ContentController::class, 'index'])
    ->middleware('revision')
    ->name('contents.index');
Route::get('contents/{slug}', [ContentController::class, 'show'])
    ->middleware('revision')
    ->where('slug', '.*')
    ->name('contents.show');

Route::get('redirects', RedirectController::class)->name('api.data.redirects.index');
Route::post('redirects/lookup', RedirectLookupController::class)->name('api.data.redirects.lookup');

Route::get('spaces/me', SpaceController::class)->name('spaces.show');

Route::get('blocks', [BlockController::class, 'index'])->name('blocks.index');
Route::get('blocks/{block}', [BlockController::class, 'show'])->name('blocks.show');

Route::get('datasources', [DataSourceController::class, 'index'])->name('datasources.index');
Route::get('datasources/{source:slug}/entries', [DataEntryController::class, 'index'])->name('dataentries.index');
