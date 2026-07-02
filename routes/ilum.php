<?php

use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['ilum'])->group(function () {
    Route::get('/{storage}/{space}/{assetId}/{name}', [ImageController::class, 'process'])
        ->where('name', '[A-Za-z0-9_\-.]+')
        ->name('original');

    Route::get('/{storage}/{space}/{assetId}/{name}/{transformations}', [ImageController::class, 'process'])
        ->where('name', '[A-Za-z0-9_\-.]+')
        ->where('transformations', '[a-zA-Z0-9_,.]+')
        ->name('transform');
});
