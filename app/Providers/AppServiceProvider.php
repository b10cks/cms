<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use PostHog\PostHog;
use Symfony\Component\HttpFoundation\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (app()->environment('production')) {
            request()->setTrustedProxies(['*'], Request::HEADER_X_FORWARDED_AWS_ELB);
            \URL::forceScheme('https');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(!$this->app->isProduction());

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->components->addSecurityScheme('token', SecurityScheme::apiKey('query', 'token'));
                $openApi->security[] = new SecurityRequirement([
                    'token' => [],
                ]);
            });

        if (config('services.posthog.api_key')) {
            PostHog::init(
                config('services.posthog.api_key'),
                config('services.posthog.settings', [])
            );
        }
    }
}
