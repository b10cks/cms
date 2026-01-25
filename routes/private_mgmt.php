<?php

use App\Http\Controllers\Mgmt\Ai\AvailableModelsController;
use App\Http\Controllers\Mgmt\Ai\MetaTagsController;
use App\Http\Controllers\Mgmt\Ai\TranslationController;
use App\Http\Controllers\Mgmt\AssetController;
use App\Http\Controllers\Mgmt\AssetDataExportController;
use App\Http\Controllers\Mgmt\AssetDataImportController;
use App\Http\Controllers\Mgmt\AssetFolderController;
use App\Http\Controllers\Mgmt\AssetTagController;
use App\Http\Controllers\Mgmt\BlockController;
use App\Http\Controllers\Mgmt\BlockFolderController;
use App\Http\Controllers\Mgmt\BlockTagController;
use App\Http\Controllers\Mgmt\Content\ContentController;
use App\Http\Controllers\Mgmt\Content\ContentMenuController;
use App\Http\Controllers\Mgmt\Content\ContentPublishController;
use App\Http\Controllers\Mgmt\Content\ContentUnpublishController;
use App\Http\Controllers\Mgmt\Content\ContentVersionController;
use App\Http\Controllers\Mgmt\Content\ContentVersionCurrentController;
use App\Http\Controllers\Mgmt\Content\ContentVersionPublishController;
use App\Http\Controllers\Mgmt\RedirectController;
use App\Http\Controllers\Mgmt\RedirectResetController;
use App\Http\Controllers\Mgmt\SpaceArchiveController;
use App\Http\Controllers\Mgmt\SpaceController;
use App\Http\Controllers\Mgmt\SpaceIconController;
use App\Http\Controllers\Mgmt\SpaceSearchController;
use App\Http\Controllers\Mgmt\SpaceStatsController;
use App\Http\Controllers\Mgmt\SpaceTokenController;
use App\Http\Controllers\Mgmt\TeamController;
use App\Http\Controllers\Mgmt\TeamHierarchyController;
use App\Http\Controllers\Mgmt\TeamUserController;
use App\Http\Controllers\Mgmt\User\UserAvatarController;
use App\Http\Controllers\Mgmt\User\UserController;
use App\Http\Controllers\Mgmt\User\UserPasswordController;
use App\Http\Controllers\Mgmt\User\UserSettingsController;
use App\Http\Controllers\SpaceAiUsageController;

Route::group(['prefix' => 'users'], function () {
    Route::group(['prefix' => 'me'], function () {
        Route::get('/', [UserController::class, 'show'])->name('users.me.show');
        Route::patch('/', [UserController::class, 'update'])->name('users.me.update');
        Route::post('/settings', UserSettingsController::class)->name('users.me.settings');
        Route::post('/avatar', UserAvatarController::class)->name('users.me.avatar');

        Route::post('/password', UserPasswordController::class)
            ->middleware(['throttle:crucial'])
            ->name('users.me.password');
    });
});

Route::group(['prefix' => 'ai'], function () {
    Route::get('available-models', AvailableModelsController::class)
        ->name('ai.available-models');
    Route::post('meta-tags', MetaTagsController::class)
        ->name('ai.meta-tags');
    Route::post('translate', TranslationController::class)
        ->name('ai.translate');
});

Route::apiResource('teams', TeamController::class);
Route::get('teams/hierarchy', TeamHierarchyController::class)->name('teams.hierarchy');

Route::group(['prefix' => 'teams/{team}'], function () {
    Route::post('users', [TeamUserController::class, 'store'])->name('teams.users.store');
    Route::patch('users/{user}', [TeamUserController::class, 'update'])->name('teams.users.update');
    Route::delete('users/{user}', [TeamUserController::class, 'destroy'])->name('teams.users.destroy');
});


Route::apiResource('spaces', SpaceController::class);
Route::group(['prefix' => 'spaces/{space}'], function () {
    Route::post('icon', SpaceIconController::class)->name('spaces.icon');
    Route::post('archive', SpaceArchiveController::class)->name('spaces.archive');
    Route::get('ai-usage', SpaceAiUsageController::class)->name('spaces.ai-usage');

    Route::patch('search', [SpaceSearchController::class, 'update'])->name('spaces.search.update');
    Route::post('search/reindex', [SpaceSearchController::class, 'reindex'])->name('spaces.search.reindex');

    Route::apiResource('blocks', BlockController::class);
    Route::apiResource('block-tags', BlockTagController::class)->parameters([
        'block-tags' => 'tag'
    ]);

    Route::apiResource('block-folders', BlockFolderController::class)->parameters([
        'block-folders' => 'folder'
    ]);
    Route::apiResource('contents', ContentController::class);
    Route::apiResource('tokens', SpaceTokenController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('redirects', RedirectController::class);
    Route::post('redirects/{redirect}/reset', RedirectResetController::class)->name('redirects.reset');

    Route::get('content-menu', ContentMenuController::class);
    Route::get('stats', SpaceStatsController::class);

    Route::apiResource('asset-folders', AssetFolderController::class);
    Route::apiResource('asset-tags', AssetTagController::class);
    Route::apiResource('assets', AssetController::class);

    Route::post('assets/export', AssetDataExportController::class)
        ->name('assets.data.export');
    Route::post('assets/import', AssetDataImportController::class)
        ->name('assets.data.import');

    // Additional content actions
    Route::post('contents/{content}/publish', ContentPublishController::class)
        ->name('contents.publish');
    Route::post('contents/{content}/unpublish', ContentUnpublishController::class)
        ->name('contents.unpublish');

    Route::get('/contents/{content}/versions', [ContentVersionController::class, 'index'])
        ->name('contents.versions.index');

    Route::get('/contents/{content}/versions/{version}', [ContentVersionController::class, 'show'])
        ->name('contents.versions.show');
    Route::patch('/contents/{content}/versions/{version}', [ContentVersionController::class, 'update'])
        ->name('contents.versions.update');

    Route::post('/contents/{content}/versions/{version}/publish', ContentVersionPublishController::class)
        ->name('contents.versions.publish');
    Route::post('/contents/{content}/versions/{version}/current', ContentVersionCurrentController::class)
        ->name('contents.versions.current');

    Route::apiResource('data-sources', \App\Http\Controllers\Mgmt\DataSourceController::class);
    Route::apiResource('data-sources.entries', \App\Http\Controllers\Mgmt\DataEntryController::class);

});
