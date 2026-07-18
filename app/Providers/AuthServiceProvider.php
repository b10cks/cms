<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\Management\Invite;
use App\Models\Management\Automation;
use App\Models\Management\AutomationAction;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Models\Management\SpaceBlueprint;
use App\Models\Management\SpaceMigration;
use App\Models\Management\Team;
use App\Models\Management\Token;
use App\Models\Space\AuditLog;
use App\Models\Space\Block;
use App\Models\Space\BlockTemplate;
use App\Models\Space\BlockVersion;
use App\Models\Space\Content;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\Space\FieldPlugin;
use App\Models\Space\Redirect;
use App\Models\Space\Release;
use App\Policies\AuditLogPolicy;
use App\Policies\BlockPolicy;
use App\Policies\BlockTemplatePolicy;
use App\Policies\BlockVersionPolicy;
use App\Policies\ContentPolicy;
use App\Policies\DataEntryPolicy;
use App\Policies\DataSourcePolicy;
use App\Policies\FieldPluginPolicy;
use App\Policies\InvitePolicy;
use App\Policies\AutomationActionPolicy;
use App\Policies\AutomationPolicy;
use App\Policies\PersonalAccessTokenPolicy;
use App\Policies\RedirectPolicy;
use App\Policies\ReleasePolicy;
use App\Policies\SpaceBackupPolicy;
use App\Policies\SpaceBlueprintPolicy;
use App\Policies\SpaceMigrationPolicy;
use App\Policies\SpacePolicy;
use App\Policies\TeamPolicy;
use App\Policies\TokenPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        AuditLog::class => AuditLogPolicy::class,
        Automation::class => AutomationPolicy::class,
        AutomationAction::class => AutomationActionPolicy::class,
        Block::class => BlockPolicy::class,
        BlockTemplate::class => BlockTemplatePolicy::class,
        BlockVersion::class => BlockVersionPolicy::class,
        Content::class => ContentPolicy::class,
        DataEntry::class => DataEntryPolicy::class,
        DataSource::class => DataSourcePolicy::class,
        FieldPlugin::class => FieldPluginPolicy::class,
        Invite::class => InvitePolicy::class,
        Redirect::class => RedirectPolicy::class,
        Release::class => ReleasePolicy::class,
        SpaceBackup::class => SpaceBackupPolicy::class,
        SpaceMigration::class => SpaceMigrationPolicy::class,
        Team::class => TeamPolicy::class,
        Token::class => TokenPolicy::class,
        PersonalAccessToken::class => PersonalAccessTokenPolicy::class,
        Space::class => SpacePolicy::class,
        SpaceBlueprint::class => SpaceBlueprintPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void {}
}
