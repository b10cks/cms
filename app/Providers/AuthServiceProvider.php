<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Management\Token;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\Space\Redirect;
use App\Policies\BlockPolicy;
use App\Policies\ContentPolicy;
use App\Policies\DataEntryPolicy;
use App\Policies\DataSourcePolicy;
use App\Policies\RedirectPolicy;
use App\Policies\SpacePolicy;
use App\Policies\TeamPolicy;
use App\Policies\TokenPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Block::class => BlockPolicy::class,
        Content::class => ContentPolicy::class,
        DataEntry::class => DataEntryPolicy::class,
        DataSource::class => DataSourcePolicy::class,
        Redirect::class => RedirectPolicy::class,
        Team::class => TeamPolicy::class,
        Token::class => TokenPolicy::class,
        Space::class => SpacePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {

    }
}
