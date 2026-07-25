<?php

use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['ilum'])->group(function () {
    // Poster routes must be declared before the generic transformation route,
    // which would otherwise swallow the literal `poster` segment.
    Route::get('/{storage}/{space}/{assetId}/{name}/poster', [ImageController::class, 'poster'])
        ->where('name', '[A-Za-z0-9_\-.]+')
        ->name('poster');

    Route::get('/{storage}/{space}/{assetId}/{name}/poster/{transformations}', [ImageController::class, 'poster'])
        ->where('name', '[A-Za-z0-9_\-.]+')
        ->where('transformations', '[a-zA-Z0-9_,.]+')
        ->name('poster.transform');

    Route::get('/{storage}/{space}/{assetId}/{name}', [ImageController::class, 'process'])
        ->where('name', '[A-Za-z0-9_\-.]+')
        ->name('original');

    Route::get('/{storage}/{space}/{assetId}/{name}/{transformations}', [ImageController::class, 'process'])
        ->where('name', '[A-Za-z0-9_\-.]+')
        ->where('transformations', '[a-zA-Z0-9_,.]+')
        ->name('transform');
});
