<?php

use App\Http\Controllers\Mgmt\Ai\AiAttachmentController;
use App\Http\Controllers\Mgmt\Ai\AiChangesetController;
use App\Http\Controllers\Mgmt\Ai\AiContextController;
use App\Http\Controllers\Mgmt\Ai\AiGlossaryController;
use App\Http\Controllers\Mgmt\Ai\AiGlossarySuggestionController;
use App\Http\Controllers\Mgmt\Ai\AiKnowledgeDocumentController;
use App\Http\Controllers\Mgmt\Ai\AiModelsController;
use App\Http\Controllers\Mgmt\Ai\AiPreflightController;
use App\Http\Controllers\Mgmt\Ai\AiRunController;
use App\Http\Controllers\Mgmt\Ai\AiScheduleController;
use App\Http\Controllers\Mgmt\Ai\AiSkillController;
use App\Http\Controllers\Mgmt\Ai\AiThreadController;
use App\Http\Controllers\Mgmt\Ai\AssetClassificationController;
use App\Http\Controllers\Mgmt\Ai\AvailableModelsController;
use App\Http\Controllers\Mgmt\Ai\SpaceAiConfigController;
use App\Http\Controllers\Mgmt\Ai\SpaceAiSettingsController;
use App\Http\Controllers\Mgmt\AssetCollectionAssetController;
use App\Http\Controllers\Mgmt\AssetCollectionController;
use App\Http\Controllers\Mgmt\AssetController;
use App\Http\Controllers\Mgmt\AssetDataExportController;
use App\Http\Controllers\Mgmt\AssetDataImportController;
use App\Http\Controllers\Mgmt\AssetFolderController;
use App\Http\Controllers\Mgmt\AssetLinkedContentController;
use App\Http\Controllers\Mgmt\AssetPackageController;
use App\Http\Controllers\Mgmt\AssetShareController;
use App\Http\Controllers\Mgmt\AssetTagController;
use App\Http\Controllers\Mgmt\AssetVersionController;
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
use App\Http\Controllers\Mgmt\Content\ContentBulkPublishController;
use App\Http\Controllers\Mgmt\Content\ContentController;
use App\Http\Controllers\Mgmt\Content\ContentCreateAndPublishController;
use App\Http\Controllers\Mgmt\Content\ContentDataExportController;
use App\Http\Controllers\Mgmt\Content\ContentDataImportController;
use App\Http\Controllers\Mgmt\Content\ContentMassEditController;
use App\Http\Controllers\Mgmt\Content\ContentMenuController;
use App\Http\Controllers\Mgmt\Content\ContentPublishController;
use App\Http\Controllers\Mgmt\Content\ContentScheduleController;
use App\Http\Controllers\Mgmt\Content\ContentSerialPreviewController;
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
use App\Http\Controllers\Mgmt\EnsureAssetFolderPathsController;
use App\Http\Controllers\Mgmt\FieldPluginController;
use App\Http\Controllers\Mgmt\IconController;
use App\Http\Controllers\Mgmt\IconDataImportController;
use App\Http\Controllers\Mgmt\MigrationController;
use App\Http\Controllers\Mgmt\NotificationController;
use App\Http\Controllers\Mgmt\PlanProposalController;
use App\Http\Controllers\Mgmt\Provider\ProviderMetricsController;
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
use App\Http\Controllers\Mgmt\SpaceOnboardingController;
use App\Http\Controllers\Mgmt\SpacePeopleController;
use App\Http\Controllers\Mgmt\SpacePlansController;
use App\Http\Controllers\Mgmt\SpaceRoleController;
use App\Http\Controllers\Mgmt\SpaceSearchController;
use App\Http\Controllers\Mgmt\SpaceStatsController;
use App\Http\Controllers\Mgmt\SpaceSubscriptionController;
use App\Http\Controllers\Mgmt\SpaceTokenController;
use App\Http\Controllers\Mgmt\SystemSpaceBlueprintController;
use App\Http\Controllers\Mgmt\TeamAvatarController;
use App\Http\Controllers\Mgmt\TeamController;
use App\Http\Controllers\Mgmt\TeamHierarchyController;
use App\Http\Controllers\Mgmt\TeamInviteController;
use App\Http\Controllers\Mgmt\TeamInviteResendController;
use App\Http\Controllers\Mgmt\TeamMemberController;
use App\Http\Controllers\Mgmt\TeamPeopleController;
use App\Http\Controllers\Mgmt\TeamOverviewController;
use App\Http\Controllers\Mgmt\TeamAccessMatrixController;
use App\Http\Controllers\Mgmt\TeamAuditLogController;
use App\Http\Controllers\Mgmt\MembershipExpiryController;
use App\Http\Controllers\Mgmt\TeamDomainController;
use App\Http\Controllers\Mgmt\TeamJoinRequestController;
use App\Http\Controllers\Mgmt\TeamSamlProviderController;
use App\Http\Controllers\Mgmt\Provider\ProviderConsoleController;
use App\Http\Controllers\Mgmt\Provider\ProviderUserController;
use App\Http\Controllers\Mgmt\Root\RootElevationController;
use App\Http\Controllers\Mgmt\TeamUserController;
use App\Http\Controllers\Mgmt\User\UserAvatarController;
use App\Http\Controllers\Mgmt\User\UserController;
use App\Http\Controllers\Mgmt\User\UserInviteController;
use App\Http\Controllers\Mgmt\User\UserJoinRequestController;
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

        // An operator impersonating a user must not be able to take the
        // account over: no password changes, no minting API tokens as them.
        Route::post('/password', UserPasswordController::class)
            ->middleware(['throttle:crucial', 'not-impersonating'])
            ->name('users.me.password');

        Route::get('/tokens', [UserTokenController::class, 'index'])
            ->name('users.me.tokens.index');
        // Minting a token creates a bearer credential that outlives the
        // session it was created from — logging out does not revoke it and
        // neither does changing the password, by design. Whoever mints one
        // therefore has to prove they are the account owner right now: a TOTP
        // code (or a backup code) when a second factor is enrolled, the
        // password when it is not.
        Route::post('/tokens', [UserTokenController::class, 'store'])
            ->middleware(['throttle:crucial', 'not-impersonating', '2fa'])
            ->name('users.me.tokens.store');
        Route::delete('/tokens/{token}', [UserTokenController::class, 'destroy'])
            ->name('users.me.tokens.destroy');

        Route::get('/invites', [UserInviteController::class, 'index'])->name('users.me.invites.index');
        Route::get('/invites/{invite}', [UserInviteController::class, 'show'])->name('users.me.invites.show');
        Route::post('/invites/{invite}/accept', [UserInviteController::class, 'accept'])
            ->middleware('throttle:crucial')
            ->name('users.me.invites.accept');
        Route::post('/invites/{invite}/decline', [UserInviteController::class, 'decline'])
            ->middleware('throttle:crucial')
            ->name('users.me.invites.decline');

        Route::get('/join-requests', [UserJoinRequestController::class, 'index'])->name('users.me.join-requests.index');
        Route::post('/join-requests', [UserJoinRequestController::class, 'store'])
            ->middleware('throttle:crucial')
            ->name('users.me.join-requests.store');
        Route::delete('/join-requests/{joinRequest}', [UserJoinRequestController::class, 'destroy'])
            ->name('users.me.join-requests.destroy');

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

// Root elevation: reads stay unconditional for root, writes need a running elevation.
Route::group(['prefix' => 'root/elevation', 'middleware' => ['not-impersonating']], function () {
    Route::get('/', [RootElevationController::class, 'show'])->name('root.elevation.show');
    Route::post('/', [RootElevationController::class, 'store'])->middleware('throttle:crucial')->name('root.elevation.store');
    Route::delete('/', [RootElevationController::class, 'destroy'])->name('root.elevation.destroy');
});

// Provider console. Root-only; the user actions also need an elevation (form requests).
Route::group(['prefix' => 'provider', 'middleware' => ['root', 'not-impersonating']], function () {
    Route::get('tenants', [ProviderConsoleController::class, 'tenants'])->name('provider.tenants');
    Route::get('usage', [ProviderConsoleController::class, 'usage'])->name('provider.usage');
    Route::get('health', [ProviderConsoleController::class, 'health'])->name('provider.health');
    Route::get('audit', [ProviderConsoleController::class, 'audit'])->name('provider.audit');
    Route::get('users', [ProviderUserController::class, 'index'])->name('provider.users.index');
    Route::get('users/{user}', [ProviderUserController::class, 'show'])->name('provider.users.show');
    Route::group(['middleware' => ['throttle:crucial']], function () {
        Route::post('users/{user}/suspend', [ProviderUserController::class, 'suspend'])->name('provider.users.suspend');
        Route::post('users/{user}/restore', [ProviderUserController::class, 'restore'])->name('provider.users.restore');
        Route::post('users/{user}/sign-out', [ProviderUserController::class, 'signOut'])->name('provider.users.sign-out');
        Route::post('users/{user}/transfer-ownership', [ProviderUserController::class, 'transferOwnership'])->name('provider.users.transfer-ownership');
    });
});
Route::get('provider/stats', ProviderStatsController::class)->name('provider.stats');
Route::get('provider/metrics', ProviderMetricsController::class)->name('provider.metrics');
Route::apiResource('provider/notes', ProviderNoteController::class)
    ->parameters(['notes' => 'providerNote']);

Route::group(['prefix' => 'ai'], function () {
    Route::get('available-models', AvailableModelsController::class)
        ->name('ai.available-models');
    Route::get('models', AiModelsController::class)
        ->name('ai.models');
    Route::post('assets/classify', AssetClassificationController::class)
        ->name('ai.assets.classify');
    Route::get('assets/classify/{runId}', [AssetClassificationController::class, 'show'])
        ->name('ai.assets.classify.show');
});

Route::get('teams/hierarchy', TeamHierarchyController::class)->name('teams.hierarchy');
Route::apiResource('teams', TeamController::class);

Route::get('space-blueprints', AvailableSpaceBlueprintController::class)
    ->name('space-blueprints');

// Blueprints without a team. Everyone may start a space from one; only root
// may create or change one.
Route::post('space-blueprints', [SystemSpaceBlueprintController::class, 'store'])
    ->name('space-blueprints.store');
Route::get('space-blueprints/{blueprint}', [SystemSpaceBlueprintController::class, 'show'])
    ->name('space-blueprints.show');
Route::match(['put', 'patch'], 'space-blueprints/{blueprint}', [SystemSpaceBlueprintController::class, 'update'])
    ->name('space-blueprints.update');
Route::delete('space-blueprints/{blueprint}', [SystemSpaceBlueprintController::class, 'destroy'])
    ->name('space-blueprints.destroy');

/**
 * Skills, knowledge documents and glossaries, registered for spaces and teams.
 */
$aiKnowledgeRoutes = static function (string $name): void {
    Route::get('ai/skills', [AiSkillController::class, 'index'])->name("{$name}.ai.skills.index");
    Route::post('ai/skills', [AiSkillController::class, 'store'])->name("{$name}.ai.skills.store");
    Route::post('ai/skills/customize', [AiSkillController::class, 'customize'])->name("{$name}.ai.skills.customize");
    Route::get('ai/skills/{skill}', [AiSkillController::class, 'show'])->name("{$name}.ai.skills.show");
    Route::patch('ai/skills/{skill}', [AiSkillController::class, 'update'])->name("{$name}.ai.skills.update");
    Route::delete('ai/skills/{skill}', [AiSkillController::class, 'destroy'])->name("{$name}.ai.skills.destroy");
    Route::post('ai/skills/{skill}/publish', [AiSkillController::class, 'publish'])->name("{$name}.ai.skills.publish");

    Route::get('ai/knowledge', [AiKnowledgeDocumentController::class, 'index'])->name("{$name}.ai.knowledge.index");
    Route::post('ai/knowledge', [AiKnowledgeDocumentController::class, 'store'])->name("{$name}.ai.knowledge.store");
    Route::get('ai/knowledge/{document}', [AiKnowledgeDocumentController::class, 'show'])->name("{$name}.ai.knowledge.show");
    Route::patch('ai/knowledge/{document}', [AiKnowledgeDocumentController::class, 'update'])->name("{$name}.ai.knowledge.update");
    Route::delete('ai/knowledge/{document}', [AiKnowledgeDocumentController::class, 'destroy'])->name("{$name}.ai.knowledge.destroy");
    Route::post('ai/knowledge/{document}/publish', [AiKnowledgeDocumentController::class, 'publish'])->name("{$name}.ai.knowledge.publish");
    Route::post('ai/knowledge/{document}/source', [AiKnowledgeDocumentController::class, 'upload'])->name("{$name}.ai.knowledge.upload");

    Route::get('ai/glossaries', [AiGlossaryController::class, 'index'])->name("{$name}.ai.glossaries.index");
    Route::post('ai/glossaries', [AiGlossaryController::class, 'store'])->name("{$name}.ai.glossaries.store");
    Route::patch('ai/glossaries/{glossary}', [AiGlossaryController::class, 'update'])->name("{$name}.ai.glossaries.update");
    Route::delete('ai/glossaries/{glossary}', [AiGlossaryController::class, 'destroy'])->name("{$name}.ai.glossaries.destroy");
    Route::get('ai/glossaries/{glossary}/terms', [AiGlossaryController::class, 'terms'])->name("{$name}.ai.glossaries.terms.index");
    Route::post('ai/glossaries/{glossary}/terms', [AiGlossaryController::class, 'storeTerm'])->name("{$name}.ai.glossaries.terms.store");
    Route::patch('ai/glossaries/{glossary}/terms/{term}', [AiGlossaryController::class, 'updateTerm'])->name("{$name}.ai.glossaries.terms.update");
    Route::delete('ai/glossaries/{glossary}/terms/{term}', [AiGlossaryController::class, 'destroyTerm'])->name("{$name}.ai.glossaries.terms.destroy");
    Route::post('ai/glossaries/{glossary}/import', [AiGlossaryController::class, 'import'])->name("{$name}.ai.glossaries.import");
    Route::get('ai/glossaries/{glossary}/export', [AiGlossaryController::class, 'export'])->name("{$name}.ai.glossaries.export");
};

Route::group(['prefix' => 'teams/{team}'], function () use ($aiKnowledgeRoutes) {
    $aiKnowledgeRoutes('teams');

    Route::post('avatar', [TeamAvatarController::class, 'store'])->name('teams.avatar.store');
    Route::delete('avatar', [TeamAvatarController::class, 'destroy'])->name('teams.avatar.destroy');

    Route::get('people', [TeamPeopleController::class, 'index'])->name('teams.people.index');
    Route::get('overview', [TeamOverviewController::class, 'show'])->name('teams.overview');
    Route::get('move-preview', [TeamOverviewController::class, 'movePreview'])->name('teams.move-preview');
    Route::get('access-matrix', TeamAccessMatrixController::class)->name('teams.access-matrix');
    Route::get('audit-logs', [TeamAuditLogController::class, 'index'])->name('teams.audit-logs.index');
    Route::put('users/{user}/expiry', [MembershipExpiryController::class, 'team'])->name('teams.users.expiry');

    Route::get('members', [TeamMemberController::class, 'index'])->name('teams.members.index');
    Route::get('users', [TeamMemberController::class, 'index'])->name('teams.users.index');
    Route::patch('members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
    Route::delete('members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

    Route::post('users', [TeamUserController::class, 'store'])->name('teams.users.store');
    Route::patch('users/{user}', [TeamUserController::class, 'update'])->name('teams.users.update');
    Route::delete('users/{user}', [TeamUserController::class, 'destroy'])->name('teams.users.destroy');

    Route::get('invites', [TeamInviteController::class, 'index'])->name('teams.invites.index');
    Route::post('invites', [TeamInviteController::class, 'store'])
        ->middleware('throttle:invites')
        ->name('teams.invites.store');
    Route::delete('invites/{invite}', [TeamInviteController::class, 'destroy'])->name('teams.invites.destroy');
    Route::post('invites/{invite}/resend', TeamInviteResendController::class)
        ->middleware('throttle:invites')
        ->name('teams.invites.resend');

    Route::get('saml-provider', [TeamSamlProviderController::class, 'show'])->name('teams.saml-provider.show');
    Route::put('saml-provider', [TeamSamlProviderController::class, 'upsert'])->name('teams.saml-provider.upsert');
    Route::delete('saml-provider', [TeamSamlProviderController::class, 'destroy'])->name('teams.saml-provider.destroy');

    Route::get('domains', [TeamDomainController::class, 'index'])->name('teams.domains.index');
    Route::post('domains', [TeamDomainController::class, 'store'])->name('teams.domains.store');
    Route::patch('domains/{domain}', [TeamDomainController::class, 'update'])->name('teams.domains.update');
    Route::delete('domains/{domain}', [TeamDomainController::class, 'destroy'])->name('teams.domains.destroy');
    Route::post('domains/{domain}/verify', [TeamDomainController::class, 'verify'])
        ->middleware('throttle:crucial')
        ->name('teams.domains.verify');

    Route::get('join-requests', [TeamJoinRequestController::class, 'index'])->name('teams.join-requests.index');
    Route::post('join-requests/{joinRequest}/approve', [TeamJoinRequestController::class, 'approve'])->name('teams.join-requests.approve');
    Route::post('join-requests/{joinRequest}/decline', [TeamJoinRequestController::class, 'decline'])->name('teams.join-requests.decline');

    Route::apiResource('blueprints', SpaceBlueprintController::class);

    Route::get('roles/space', [SpaceRoleController::class, 'index'])->name('teams.roles.space.index');
    Route::post('roles/space', [SpaceRoleController::class, 'store'])->name('teams.roles.space.store');
    Route::patch('roles/space/{role}', [SpaceRoleController::class, 'update'])->name('teams.roles.space.update');
    Route::delete('roles/space/{role}', [SpaceRoleController::class, 'destroy'])->name('teams.roles.space.destroy');
});

Route::apiResource('spaces', SpaceController::class);
Route::group(['prefix' => 'spaces/{space}', 'middleware' => 'space.member'], function () use ($aiKnowledgeRoutes) {
    $aiKnowledgeRoutes('spaces');
    Route::get('ai/context', AiContextController::class)->name('spaces.ai.context');
    Route::post('ai/runs', [AiRunController::class, 'store'])->name('spaces.ai.runs.store');
    Route::get('ai/runs/{run}', [AiRunController::class, 'show'])->name('spaces.ai.runs.show');
    Route::get('ai/runs/{run}/events', [AiRunController::class, 'events'])->name('spaces.ai.runs.events');
    Route::post('ai/runs/{run}/answers', [AiRunController::class, 'answer'])->name('spaces.ai.runs.answer');
    Route::post('ai/runs/{run}/cancel', [AiRunController::class, 'cancel'])->name('spaces.ai.runs.cancel');
    Route::post('ai/attachments', [AiAttachmentController::class, 'store'])->name('spaces.ai.attachments.store');
    Route::get('ai/attachments/{attachment}', [AiAttachmentController::class, 'show'])->name('spaces.ai.attachments.show');
    Route::post('ai/preflight', AiPreflightController::class)->name('spaces.ai.preflight');
    Route::get('ai/threads', [AiThreadController::class, 'index'])->name('spaces.ai.threads.index');
    Route::get('ai/threads/{thread}', [AiThreadController::class, 'show'])->name('spaces.ai.threads.show');
    Route::patch('ai/threads/{thread}', [AiThreadController::class, 'update'])->name('spaces.ai.threads.update');
    Route::get('ai/changesets/{changeset}', [AiChangesetController::class, 'show'])->name('spaces.ai.changesets.show');
    Route::post('ai/changesets/{changeset}/accept', [AiChangesetController::class, 'accept'])->name('spaces.ai.changesets.accept');
    Route::post('ai/changesets/{changeset}/reject', [AiChangesetController::class, 'reject'])->name('spaces.ai.changesets.reject');
    Route::post('ai/suggestions/{suggestion}/accept', [AiGlossarySuggestionController::class, 'accept'])->name('spaces.ai.suggestions.accept');
    Route::post('ai/suggestions/{suggestion}/dismiss', [AiGlossarySuggestionController::class, 'dismiss'])->name('spaces.ai.suggestions.dismiss');
    Route::get('ai/schedules', [AiScheduleController::class, 'index'])->name('spaces.ai.schedules.index');
    Route::post('ai/schedules', [AiScheduleController::class, 'store'])->name('spaces.ai.schedules.store');
    Route::patch('ai/schedules/{schedule}', [AiScheduleController::class, 'update'])->name('spaces.ai.schedules.update');
    Route::delete('ai/schedules/{schedule}', [AiScheduleController::class, 'destroy'])->name('spaces.ai.schedules.destroy');

    Route::post('icon', SpaceIconController::class)->name('spaces.icon');
    Route::post('archive', SpaceArchiveController::class)->name('spaces.archive');
    Route::patch('onboarding', [SpaceOnboardingController::class, 'update'])->name('spaces.onboarding.update');
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
    Route::post('invites', [SpaceInviteController::class, 'store'])
        ->middleware('throttle:invites')
        ->name('spaces.invites.store');
    Route::delete('invites/{invite}', [SpaceInviteController::class, 'destroy'])->name('spaces.invites.destroy');
    Route::post('invites/{invite}/resend', SpaceInviteResendController::class)
        ->middleware('throttle:invites')
        ->name('spaces.invites.resend');

    Route::get('people', [SpacePeopleController::class, 'index'])->name('spaces.people.index');

    Route::get('members', [SpaceMemberController::class, 'index'])->name('spaces.members.index');
    Route::post('members', [SpaceMemberController::class, 'store'])->name('spaces.members.store');
    Route::put('members/{user}/expiry', [MembershipExpiryController::class, 'space'])->name('spaces.members.expiry');
    Route::patch('members/{user}', [SpaceMemberController::class, 'update'])->name('spaces.members.update');
    Route::delete('members/{user}', [SpaceMemberController::class, 'destroy'])->name('spaces.members.destroy');

    Route::patch('search', [SpaceSearchController::class, 'update'])->name('spaces.search.update');
    Route::post('search/reindex', [SpaceSearchController::class, 'reindex'])->name('spaces.search.reindex');

    Route::put('blocks/sync', [BlockController::class, 'sync'])->name('blocks.sync');
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
    Route::post('contents/serial-preview', ContentSerialPreviewController::class)
        ->name('contents.serial-preview');
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

    Route::apiResource('asset-folders', AssetFolderController::class);
    Route::post('asset-folders/ensure-paths', EnsureAssetFolderPathsController::class)
        ->name('asset-folders.ensure-paths');
    Route::apiResource('asset-tags', AssetTagController::class)->parameters([
        'asset-tags' => 'tag',
    ]);
    Route::post('asset-tags/{tag}/assign', [AssetTagController::class, 'assignAssets'])
        ->name('asset-tags.assign');
    Route::get('assets/{asset}/linked-contents', AssetLinkedContentController::class)
        ->name('assets.linked-contents');
    Route::post('assets/{asset}/replace-file', [AssetController::class, 'replaceFile'])
        ->name('assets.replace-file');
    Route::post('assets/{asset}/poster', [AssetController::class, 'uploadPoster'])
        ->name('assets.poster');
    Route::delete('assets/{asset}/poster', [AssetController::class, 'removePoster'])
        ->name('assets.poster.remove');
    Route::get('assets/{asset}/versions', [AssetVersionController::class, 'index'])
        ->name('assets.versions.index');
    Route::post('assets/{asset}/versions/{version}/restore', [AssetVersionController::class, 'restore'])
        ->name('assets.versions.restore');
    Route::apiResource('assets', AssetController::class);

    Route::get('asset-collections/{collection}/assets', [AssetCollectionAssetController::class, 'index'])
        ->name('asset-collections.assets.index');
    Route::post('asset-collections/{collection}/assets', [AssetCollectionAssetController::class, 'store'])
        ->name('asset-collections.assets.store');
    Route::delete('asset-collections/{collection}/assets', [AssetCollectionAssetController::class, 'destroy'])
        ->name('asset-collections.assets.destroy');
    Route::patch('asset-collections/{collection}/assets/order', [AssetCollectionAssetController::class, 'reorder'])
        ->name('asset-collections.assets.reorder');
    Route::apiResource('asset-collections', AssetCollectionController::class)->parameters([
        'asset-collections' => 'collection',
    ]);

    Route::get('asset-packages/{package}/download', [AssetPackageController::class, 'download'])
        ->name('asset-packages.download');
    Route::apiResource('asset-packages', AssetPackageController::class)
        ->only(['index', 'show', 'store', 'destroy'])
        ->parameters(['asset-packages' => 'package']);

    Route::post('asset-shares/{share}/revoke', [AssetShareController::class, 'revoke'])
        ->name('asset-shares.revoke');
    Route::apiResource('asset-shares', AssetShareController::class)->parameters([
        'asset-shares' => 'share',
    ]);

    Route::get('icons/tags', [IconController::class, 'tags'])->name('icons.tags');
    Route::post('icons/import', IconDataImportController::class)->name('icons.import');
    Route::apiResource('icons', IconController::class);

    Route::apiResource('backups', BackupController::class);
    Route::apiResource('migrations', MigrationController::class)->only(['index', 'show', 'store', 'destroy']);

    Route::get('subscriptions', [SpaceSubscriptionController::class, 'index'])->name('spaces.subscriptions.index');
    Route::get('subscriptions/current', [SpaceSubscriptionController::class, 'current'])->name('spaces.subscriptions.current');
    Route::post('subscriptions/checkout', [SpaceSubscriptionController::class, 'checkout'])->name('spaces.subscriptions.checkout');
    Route::post('subscriptions/reinit', [SpaceSubscriptionController::class, 'reinit'])->name('spaces.subscriptions.reinit');
    Route::delete('subscriptions/pending', [SpaceSubscriptionController::class, 'discardPending'])->name('spaces.subscriptions.pending.destroy');
    Route::post('subscriptions/cancel', [SpaceSubscriptionController::class, 'cancel'])->name('spaces.subscriptions.cancel');
    Route::post('subscriptions/resume', [SpaceSubscriptionController::class, 'resume'])->name('spaces.subscriptions.resume');
    Route::get('subscriptions/proposal', [PlanProposalController::class, 'show'])->name('spaces.subscriptions.proposal.show');
    Route::post('subscriptions/proposal', [PlanProposalController::class, 'store'])->name('spaces.subscriptions.proposal.store');
    Route::delete('subscriptions/proposal', [PlanProposalController::class, 'destroy'])->name('spaces.subscriptions.proposal.destroy');
    Route::get('plans', SpacePlansController::class)->name('spaces.plans.index');

    Route::get('invoices', SpaceInvoiceController::class)->name('spaces.invoices');

    Route::post('assets/export', AssetDataExportController::class)
        ->name('assets.data.export');
    Route::post('assets/import', AssetDataImportController::class)
        ->name('assets.data.import');

    Route::post('contents/export', ContentDataExportController::class)
        ->name('contents.data.export');
    Route::post('contents/import', ContentDataImportController::class)
        ->name('contents.data.import');

    Route::get('mass-edit/fields', [ContentMassEditController::class, 'fields'])
        ->name('contents.mass-edit.fields');
    Route::get('mass-edit/rows', [ContentMassEditController::class, 'rows'])
        ->name('contents.mass-edit.rows');
    Route::patch('mass-edit/rows', [ContentMassEditController::class, 'save'])
        ->name('contents.mass-edit.save');

    // Additional content actions
    // Create-and-publish for entries that do not exist yet (e.g. a language
    // version the editor has only drafted client side).
    Route::post('contents/publish', ContentCreateAndPublishController::class)
        ->name('contents.create-publish');
    Route::post('contents/bulk-publish', [ContentBulkPublishController::class, 'publish'])
        ->name('contents.bulk-publish');
    Route::post('contents/bulk-unpublish', [ContentBulkPublishController::class, 'unpublish'])
        ->name('contents.bulk-unpublish');
    Route::get('contents/bulk-publish/{run}', [ContentBulkPublishController::class, 'show'])
        ->name('contents.bulk-publish.show');
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
    Route::apiResource('field-plugins', FieldPluginController::class);
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
