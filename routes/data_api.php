<?php

use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DataEntryController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\RedirectController;
use App\Http\Controllers\Api\RedirectLookupController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SpaceController;

Route::get('contents', [ContentController::class, 'index'])
    ->middleware(['revision', 'cache.data'])
    ->name('contents.index');
Route::get('search', SearchController::class)
    ->middleware(['cache.data:60:60'])
    ->name('contents.search');
Route::get('contents/{slug}', [ContentController::class, 'show'])
    ->middleware(['revision', 'cache.data'])
    ->where('slug', '.*')
    ->name('contents.show');

Route::get('redirects', RedirectController::class)
    ->middleware(['cache.data:60:60'])
    ->name('api.data.redirects.index');
Route::post('redirects/lookup', RedirectLookupController::class)->name('api.data.redirects.lookup');

Route::get('spaces/me', SpaceController::class)
    ->middleware(['cache.data:60:60'])
    ->name('spaces.show');

Route::get('blocks', [BlockController::class, 'index'])
    ->middleware(['cache.data:60:60'])
    ->name('blocks.index');
Route::get('blocks/{block}', [BlockController::class, 'show'])
    ->middleware(['cache.data:60:60'])
    ->name('blocks.show');

Route::get('datasources', [DataSourceController::class, 'index'])
    ->middleware(['cache.data:60:60'])
    ->name('datasources.index');
Route::get('datasources/{source:slug}/entries', [DataEntryController::class, 'index'])
    ->middleware(['cache.data:60:60'])
    ->name('dataentries.index');
