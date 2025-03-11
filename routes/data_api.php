<?php

use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DataEntryController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\SpaceController;

Route::get('contents', [ContentController::class, 'index'])->name('contents.index');
Route::get('contents/{slug}', [ContentController::class, 'show'])
    ->where('slug', '.*')
    ->name('contents.show');

Route::get('spaces/me', SpaceController::class)->name('spaces.show');
Route::get('blocks', [BlockController::class, 'index'])->name('blocks.index');
Route::get('blocks/{block}', [BlockController::class, 'show'])->name('blocks.show');

Route::get('datasources', [DataSourceController::class, 'index'])->name('datasources.index');
Route::get('datasources/{source:slug}/entries', [DataEntryController::class, 'index'])->name('dataentries.index');
