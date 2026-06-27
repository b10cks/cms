<?php

use App\Http\Controllers\Mgmt\Ai\AiModelsController;
use App\Http\Controllers\Mgmt\Ai\AvailableModelsController;
use App\Http\Controllers\Mgmt\Ai\ContentInteractionStreamController;
use App\Http\Controllers\Mgmt\Ai\ContentTreeInteractionStreamController;
use App\Http\Controllers\Mgmt\Ai\MetaTagsStreamController;
use App\Http\Controllers\Mgmt\Ai\SpaceAiConfigController;
use App\Http\Controllers\Mgmt\Ai\SpaceAiSettingsController;
use App\Http\Controllers\Mgmt\Ai\TranslationStreamController;
use App\Http\Controllers\Mgmt\AssetController;
use App\Http\Controllers\Mgmt\AssetDataExportController;
use App\Http\Controllers\Mgmt\AssetDataImportController;
use App\Http\Controllers\Mgmt\AssetFolderController;
use App\Http\Controllers\Mgmt\AssetLinkedContentController;
use App\Http\Controllers\Mgmt\AssetTagController;
use App\Http\Controllers\Mgmt\AuditLogController;
use App\Http\Controllers\Mgmt\AuthorizationController;
use App\Http\Controllers\Mgmt\AutomationActionController;
use App\Http\Controllers\Mgmt\AutomationController;
use App\Http\Controllers\Mgmt\AutomationExecutionController;
use App\Http\Controllers\Mgmt\AutomationExecutionReplayController;
use App\Http\Controllers\Mgmt\AutomationStats\AutomationStatsExecutionController;
use App\Http\Controllers\Mgmt\AutomationStats\AutomationStatsStatisticController;
use App\Http\Controllers\Mgmt\AutomationStats\AutomationStatsSummaryController;
use App\Http\Controllers\Mgmt\AutomationStats\AutomationStatsTrendController;
use App\Http\Controllers\Mgmt\AutomationTriggerCatalogController;
use App\Http\Controllers\Mgmt\AutomationTriggerController;
use App\Http\Controllers\Mgmt\AvailableSpaceBlueprintController;
use App\Http\Controllers\Mgmt\BackupController;
use App\Http\Controllers\Mgmt\BlockController;
use App\Http\Controllers\Mgmt\BlockFolderController;
use App\Http\Controllers\Mgmt\BlockTagController;
use App\Http\Controllers\Mgmt\BlockTemplateController;
use App\Http\Controllers\Mgmt\BlockVersionController;
use App\Http\Controllers\Mgmt\Content\BulkCreateContentController;
use App\Http\Controllers\Mgmt\Content\CommentController;
use App\Http\Controllers\Mgmt\Content\CommentReactionController;
use App\Http\Controllers\Mgmt\Content\ContentController;
use App\Http\Controllers\Mgmt\Content\ContentMenuController;
use App\Http\Controllers\Mgmt\Content\ContentPublishController;
use App\Http\Controllers\Mgmt\Content\ContentScheduleController;
use App\Http\Controllers\Mgmt\Content\ContentTreeOperationsController;
use App\Http\Controllers\Mgmt\Content\ContentUnpublishController;
use App\Http\Controllers\Mgmt\Content\ContentVersionController;
use App\Http\Controllers\Mgmt\Content\ContentVersionCurrentController;
use App\Http\Controllers\Mgmt\Content\ContentVersionPublishController;
use App\Http\Controllers\Mgmt\Content\MoveContentController;
use App\Http\Controllers\Mgmt\DataEntryController;
use App\Http\Controllers\Mgmt\DataEntryDataExportController;
use App\Http\Controllers\Mgmt\DataEntryDataImportController;
use App\Http\Controllers\Mgmt\DataEntryTranslationStreamController;
use App\Http\Controllers\Mgmt\DataSourceController;
use App\Http\Controllers\Mgmt\IconController;
use App\Http\Controllers\Mgmt\MigrationController;
use App\Http\Controllers\Mgmt\NotificationController;
use App\Http\Controllers\Mgmt\PresenceController;
use App\Http\Controllers\Mgmt\Provider\ProviderNoteController;
use App\Http\Controllers\Mgmt\Provider\ProviderStatsController;
use App\Http\Controllers\Mgmt\RedirectController;
use App\Http\Controllers\Mgmt\RedirectDataExportController;
use App\Http\Controllers\Mgmt\RedirectDataImportController;
use App\Http\Controllers\Mgmt\RedirectResetController;
use App\Http\Controllers\Mgmt\Release\ReleaseCancelController;
use App\Http\Controllers\Mgmt\Release\ReleaseCommitController;
use App\Http\Controllers\Mgmt\Release\ReleaseController;
use App\Http\Controllers\Mgmt\Release\ReleasePublishController;
use App\Http\Controllers\Mgmt\Release\ReleaseVersionAssignController;
use App\Http\Controllers\Mgmt\Release\ReleaseVersionRemoveController;
use App\Http\Controllers\Mgmt\SpaceArchiveController;
use App\Http\Controllers\Mgmt\SpaceBlueprintController;
use App\Http\Controllers\Mgmt\SpaceController;
use App\Http\Controllers\Mgmt\SpaceIconController;
use App\Http\Controllers\Mgmt\SpaceInviteController;
use App\Http\Controllers\Mgmt\SpaceInviteResendController;
use App\Http\Controllers\Mgmt\SpaceInvoiceController;
use App\Http\Controllers\Mgmt\SpaceMemberController;
use App\Http\Controllers\Mgmt\SpaceRoleController;
use App\Http\Controllers\Mgmt\SpaceSearchController;
use App\Http\Controllers\Mgmt\SpaceStatsController;
use App\Http\Controllers\Mgmt\SpaceSubscriptionController;
use App\Http\Controllers\Mgmt\SpaceTokenController;
use App\Http\Controllers\Mgmt\TeamController;
use App\Http\Controllers\Mgmt\TeamHierarchyController;
use App\Http\Controllers\Mgmt\TeamInviteController;
use App\Http\Controllers\Mgmt\TeamInviteResendController;
use App\Http\Controllers\Mgmt\TeamMemberController;
use App\Http\Controllers\Mgmt\TeamSamlProviderController;
use App\Http\Controllers\Mgmt\TeamUserController;
use App\Http\Controllers\Mgmt\User\UserAvatarController;
use App\Http\Controllers\Mgmt\User\UserController;
use App\Http\Controllers\Mgmt\User\UserInviteController;
use App\Http\Controllers\Mgmt\User\UserPasswordController;
use App\Http\Controllers\Mgmt\User\UserSettingsController;
use App\Http\Controllers\Mgmt\User\UserSocialLinkController;
use App\Http\Controllers\Mgmt\User\UserTokenController;
use App\Http\Controllers\SpaceAiUsageController;
use App\Http\Controllers\SpaceUsageController;
use App\Http\Controllers\SpaceUsageHistoryController;

Route::group(['prefix' => 'users'], function () {
    Route::group(['prefix' => 'me'], function () {
        Route::get('/', [UserController::class, 'show'])->name('users.me.show');
        Route::patch('/', [UserController::class, 'update'])->name('users.me.update');
        Route::post('/settings', UserSettingsController::class)->name('users.me.settings');
        Route::post('/avatar', UserAvatarController::class)->name('users.me.avatar');

        Route::get('/social-links', [UserSocialLinkController::class, 'index'])
            ->name('users.me.social-links.index');
        Route::delete('/social-links/{provider}', [UserSocialLinkController::class, 'destroy'])
            ->middleware(['throttle:crucial'])
            ->name('users.me.social-links.destroy');

        Route::post('/password', UserPasswordController::class)
            ->middleware(['throttle:crucial'])
            ->name('users.me.password');

        Route::get('/tokens', [UserTokenController::class, 'index'])
            ->name('users.me.tokens.index');
        Route::post('/tokens', [UserTokenController::class, 'store'])
            ->name('users.me.tokens.store');
        Route::delete('/tokens/{token}', [UserTokenController::class, 'destroy'])
            ->name('users.me.tokens.destroy');

        Route::get('/invites', [UserInviteController::class, 'index'])->name('users.me.invites.index');
        Route::get('/invites/{invite}', [UserInviteController::class, 'show'])->name('users.me.invites.show');
        Route::post('/invites/{invite}/accept', [UserInviteController::class, 'accept'])
            ->middleware('throttle:crucial')
            ->name('users.me.invites.accept');

        Route::group(['prefix' => 'notifications'], function () {
            Route::get('/', [NotificationController::class, 'index'])->name('users.me.notifications.index');
            Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('users.me.notifications.unread-count');
            Route::post('/read', [NotificationController::class, 'markAllAsRead'])->name('users.me.notifications.read-all');
            Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('users.me.notifications.read');
            Route::patch('/{notification}/unread', [NotificationController::class, 'markAsUnread'])->name('users.me.notifications.unread');
            Route::delete('/read', [NotificationController::class, 'destroyAllRead'])->name('users.me.notifications.destroy-read');
            Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('users.me.notifications.destroy');
            Route::delete('/', [NotificationController::class, 'destroyAll'])->name('users.me.notifications.destroy-all');
        });
    });
});

Route::get('authorization', AuthorizationController::class)->name('authorization.show');
Route::get('provider/stats', ProviderStatsController::class)->name('provider.stats');
Route::apiResource('provider/notes', ProviderNoteController::class)
    ->parameters(['notes' => 'providerNote']);

Route::group(['prefix' => 'ai'], function () {
    Route::get('available-models', AvailableModelsController::class)
        ->name('ai.available-models');
    Route::get('models', AiModelsController::class)
        ->name('ai.models');
    Route::post('meta-tags/stream', MetaTagsStreamController::class)
        ->name('ai.meta-tags.stream');
    Route::post('translate/stream', TranslationStreamController::class)
        ->name('ai.translate.stream');
    Route::post('content-interaction/stream', ContentInteractionStreamController::class)
        ->name('ai.content-interaction.stream');
    Route::post('content-tree-interaction/stream', ContentTreeInteractionStreamController::class)
        ->name('ai.content-tree-interaction.stream');
});

Route::get('teams/hierarchy', TeamHierarchyController::class)->name('teams.hierarchy');
Route::apiResource('teams', TeamController::class);

Route::get('space-blueprints', AvailableSpaceBlueprintController::class)
    ->name('space-blueprints');

Route::group(['prefix' => 'teams/{team}'], function () {
    Route::get('members', [TeamMemberController::class, 'index'])->name('teams.members.index');
    Route::get('users', [TeamMemberController::class, 'index'])->name('teams.users.index');
    Route::patch('members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
    Route::delete('members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

    Route::post('users', [TeamUserController::class, 'store'])->name('teams.users.store');
    Route::patch('users/{user}', [TeamUserController::class, 'update'])->name('teams.users.update');
    Route::delete('users/{user}', [TeamUserController::class, 'destroy'])->name('teams.users.destroy');

    Route::get('invites', [TeamInviteController::class, 'index'])->name('teams.invites.index');
    Route::post('invites', [TeamInviteController::class, 'store'])->name('teams.invites.store');
    Route::delete('invites/{invite}', [TeamInviteController::class, 'destroy'])->name('teams.invites.destroy');
    Route::post('invites/{invite}/resend', TeamInviteResendController::class)->name('teams.invites.resend');

    Route::get('saml-provider', [TeamSamlProviderController::class, 'show'])->name('teams.saml-provider.show');
    Route::put('saml-provider', [TeamSamlProviderController::class, 'upsert'])->name('teams.saml-provider.upsert');
    Route::delete('saml-provider', [TeamSamlProviderController::class, 'destroy'])->name('teams.saml-provider.destroy');

    Route::apiResource('blueprints', SpaceBlueprintController::class);

    Route::get('roles/space', [SpaceRoleController::class, 'index'])->name('teams.roles.space.index');
    Route::post('roles/space', [SpaceRoleController::class, 'store'])->name('teams.roles.space.store');
    Route::patch('roles/space/{role}', [SpaceRoleController::class, 'update'])->name('teams.roles.space.update');
    Route::delete('roles/space/{role}', [SpaceRoleController::class, 'destroy'])->name('teams.roles.space.destroy');
});

Route::apiResource('spaces', SpaceController::class);
Route::group(['prefix' => 'spaces/{space}'], function () {
    Route::post('icon', SpaceIconController::class)->name('spaces.icon');
    Route::post('archive', SpaceArchiveController::class)->name('spaces.archive');
    Route::get('ai-usage', SpaceAiUsageController::class)->name('spaces.ai-usage');
    Route::get('usage', SpaceUsageController::class)->name('spaces.usage');
    Route::get('usage/history', [SpaceUsageHistoryController::class, 'index'])->name('spaces.usage.history');
    Route::get('usage/history/{period}/timeseries', [SpaceUsageHistoryController::class, 'timeseries'])->name('spaces.usage.history.timeseries');
    Route::get('ai-settings', [SpaceAiSettingsController::class, 'show'])->name('spaces.ai-settings.show');
    Route::patch('ai-settings', [SpaceAiSettingsController::class, 'update'])->name('spaces.ai-settings.update');

    Route::get('ai-configs', [SpaceAiConfigController::class, 'index'])->name('spaces.ai-configs.index');
    Route::post('ai-configs', [SpaceAiConfigController::class, 'store'])->name('spaces.ai-configs.store');
    Route::get('ai-configs/{aiConfig}', [SpaceAiConfigController::class, 'show'])->name('spaces.ai-configs.show');
    Route::patch('ai-configs/{aiConfig}', [SpaceAiConfigController::class, 'update'])->name('spaces.ai-configs.update');
    Route::delete('ai-configs/{aiConfig}', [SpaceAiConfigController::class, 'destroy'])->name('spaces.ai-configs.destroy');

    Route::get('invites', [SpaceInviteController::class, 'index'])->name('spaces.invites.index');
    Route::post('invites', [SpaceInviteController::class, 'store'])->name('spaces.invites.store');
    Route::delete('invites/{invite}', [SpaceInviteController::class, 'destroy'])->name('spaces.invites.destroy');
    Route::post('invites/{invite}/resend', SpaceInviteResendController::class)->name('spaces.invites.resend');

    Route::get('members', [SpaceMemberController::class, 'index'])->name('spaces.members.index');
    Route::patch('members/{user}', [SpaceMemberController::class, 'update'])->name('spaces.members.update');
    Route::delete('members/{user}', [SpaceMemberController::class, 'destroy'])->name('spaces.members.destroy');

    Route::patch('search', [SpaceSearchController::class, 'update'])->name('spaces.search.update');
    Route::post('search/reindex', [SpaceSearchController::class, 'reindex'])->name('spaces.search.reindex');

    Route::apiResource('blocks', BlockController::class);
    Route::apiResource('block-tags', BlockTagController::class)->parameters([
        'block-tags' => 'tag',
    ]);

    Route::apiResource('block-folders', BlockFolderController::class)->parameters([
        'block-folders' => 'folder',
    ]);

    Route::group(['prefix' => 'blocks/{block}'], function () {
        Route::apiResource('templates', BlockTemplateController::class);

        Route::get('versions', [BlockVersionController::class, 'index'])->name('blocks.versions.index');
        Route::get('versions/{version}', [BlockVersionController::class, 'show'])->name('blocks.versions.show');
        Route::patch('versions/{version}', [BlockVersionController::class, 'update'])->name('blocks.versions.update');
        Route::delete('versions/{version}', [BlockVersionController::class, 'destroy'])->name('blocks.versions.destroy');
        Route::post('versions/{version}/restore', [BlockVersionController::class, 'restore'])->name('blocks.versions.restore');
    });

    Route::apiResource('contents', ContentController::class);
    Route::post('contents/bulk-create', BulkCreateContentController::class)
        ->name('contents.bulk-create');
    Route::post('contents/tree-operations', ContentTreeOperationsController::class)
        ->name('contents.tree-operations');
    Route::post('contents/{content}/move', MoveContentController::class)
        ->name('contents.move');

    Route::apiResource('tokens', SpaceTokenController::class)->only(['index', 'store', 'destroy']);
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('space-audit-logs');

    Route::apiResource('redirects', RedirectController::class);
    Route::post('redirects/{redirect}/reset', RedirectResetController::class)->name('redirects.reset');
    Route::post('redirects/export', RedirectDataExportController::class)->name('redirects.data.export');
    Route::post('redirects/import', RedirectDataImportController::class)->name('redirects.data.import');

    Route::get('content-menu', ContentMenuController::class);
    Route::get('stats', SpaceStatsController::class);

    Route::post('presence', [PresenceController::class, 'updateSpacePresence'])->name('spaces.presence.update');
    Route::delete('presence', [PresenceController::class, 'leaveSpacePresence'])->name('spaces.presence.leave');
    Route::post('contents/{content}/presence', [PresenceController::class, 'updateContentPresence'])->name('spaces.contents.presence.update');
    Route::delete('contents/{content}/presence', [PresenceController::class, 'leaveContentPresence'])->name('spaces.contents.presence.leave');

    Route::apiResource('asset-folders', AssetFolderController::class);
    Route::apiResource('asset-tags', AssetTagController::class);
    Route::get('assets/{asset}/linked-contents', AssetLinkedContentController::class)
        ->name('assets.linked-contents');
    Route::apiResource('assets', AssetController::class);

    Route::get('icons/tags', [IconController::class, 'tags'])->name('icons.tags');
    Route::apiResource('icons', IconController::class);

    Route::apiResource('backups', BackupController::class);
    Route::apiResource('migrations', MigrationController::class)->only(['index', 'show', 'store', 'destroy']);

    Route::get('subscriptions', [SpaceSubscriptionController::class, 'index'])->name('spaces.subscriptions.index');
    Route::get('subscriptions/current', [SpaceSubscriptionController::class, 'current'])->name('spaces.subscriptions.current');
    Route::post('subscriptions/checkout', [SpaceSubscriptionController::class, 'checkout'])->name('spaces.subscriptions.checkout');
    Route::post('subscriptions/reinit', [SpaceSubscriptionController::class, 'reinit'])->name('spaces.subscriptions.reinit');
    Route::post('subscriptions/cancel', [SpaceSubscriptionController::class, 'cancel'])->name('spaces.subscriptions.cancel');

    Route::get('invoices', SpaceInvoiceController::class)->name('spaces.invoices');

    Route::post('assets/export', AssetDataExportController::class)
        ->name('assets.data.export');
    Route::post('assets/import', AssetDataImportController::class)
        ->name('assets.data.import');

    // Additional content actions
    Route::post('contents/{content}/publish', ContentPublishController::class)
        ->name('contents.publish');
    Route::post('contents/{content}/unpublish', ContentUnpublishController::class)
        ->name('contents.unpublish');
    Route::post('contents/{content}/schedule', ContentScheduleController::class)
        ->name('contents.schedule');

    Route::group(['prefix' => 'contents/{content}/comments'], function () {
        // Root comments
        Route::get('/', [CommentController::class, 'index'])->name('comments.index');
        Route::post('/', [CommentController::class, 'store'])->name('comments.store');

        Route::group(['prefix' => '{comment}'], function () {
            // Comment CRUD
            Route::get('/', [CommentController::class, 'show'])->name('comments.show');
            Route::patch('/', [CommentController::class, 'update'])->name('comments.update');
            Route::delete('/', [CommentController::class, 'destroy'])->name('comments.destroy');

            // Comment resolution
            Route::post('resolve', [CommentController::class, 'resolve'])->name('comments.resolve');
            Route::delete('resolve', [CommentController::class, 'unresolve'])->name('comments.unresolve');

            // Unified reactions endpoint (works for both comments and replies)
            Route::get('reactions', [CommentReactionController::class, 'index'])->name('comments.reactions.index');
            Route::post('reactions', [CommentReactionController::class, 'store'])->name('comments.reactions.store');
            Route::delete('reactions', [CommentReactionController::class, 'destroy'])->name('comments.reactions.destroy');
        });
    });

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

    Route::apiResource('data-sources', DataSourceController::class);
    Route::post('data-sources/{data_source}/entries/export', DataEntryDataExportController::class)->name('data-sources.entries.data.export');
    Route::post('data-sources/{data_source}/entries/import', DataEntryDataImportController::class)->name('data-sources.entries.data.import');
    Route::post('data-sources/{data_source}/entries/translate-missing-dimensions/stream', DataEntryTranslationStreamController::class)
        ->name('data-sources.entries.translate-missing-dimensions.stream');
    Route::apiResource('data-sources.entries', DataEntryController::class);

    // Release Management
    Route::apiResource('releases', ReleaseController::class);
    Route::group(['prefix' => 'releases/{release}'], function () {
        Route::post('commit', ReleaseCommitController::class)
            ->name('releases.commit');
        Route::post('cancel', ReleaseCancelController::class)
            ->name('releases.cancel');
        Route::post('publish', ReleasePublishController::class)
            ->name('releases.publish');
        Route::post('versions/assign', ReleaseVersionAssignController::class)
            ->name('releases.versions.assign');
        Route::delete('versions/remove', ReleaseVersionRemoveController::class)
            ->name('releases.versions.remove');
    });

    Route::apiResource('automation-actions', AutomationActionController::class);
    Route::get('automations/trigger-catalog', AutomationTriggerCatalogController::class)
        ->name('automations.trigger-catalog');
    Route::apiResource('automations', AutomationController::class);
    Route::get('automation-executions', [AutomationExecutionController::class, 'index'])
        ->name('automation-executions.index');
    Route::post('automation-executions/{automationExecution}/replay', AutomationExecutionReplayController::class)
        ->name('automation-executions.replay');
    Route::post('automations/{automation}/trigger', AutomationTriggerController::class)
        ->name('automations.trigger');
    Route::prefix('automations/{automation}/stats')
        ->name('automations.stats.')
        ->group(function () {
            Route::get('executions', AutomationStatsExecutionController::class)
                ->name('executions');
            Route::get('trends', AutomationStatsTrendController::class)
                ->name('trends');
            Route::get('statistics', AutomationStatsStatisticController::class)
                ->name('statistics');
            Route::get('summary', AutomationStatsSummaryController::class)
                ->name('summary');
        });
});
