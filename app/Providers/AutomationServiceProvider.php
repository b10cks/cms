<?php

namespace App\Providers;

use App\Services\Automation\Actions\EmailActionHandler;
use App\Services\Automation\Actions\VoidActionHandler;
use App\Services\Automation\Actions\WebhookActionHandler;
use App\Services\Automation\AutomationContextFactory;
use App\Services\Automation\AutomationDispatcher;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationUsageService;
use App\Services\Automation\Contracts\AutomationEngine as AutomationEngineContract;
use App\Services\Automation\TriggerCatalog;
use App\Services\Automation\Triggers\ManualTriggerHandler;
use App\Services\Automation\Triggers\OnDeleteTriggerHandler;
use App\Services\Automation\Triggers\OnInsertTriggerHandler;
use App\Services\Automation\Triggers\OnUpdateTriggerHandler;
use App\Services\Automation\Triggers\TimeBasedTriggerHandler;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AutomationEngineContract::class, function ($app) {
            $engine = new AutomationEngine(
                $app->make(AutomationUsageService::class),
                $app->make(AutomationContextFactory::class),
                $app->make(AutomationDispatcher::class),
                $app->make(TriggerCatalog::class),
            );

            $engine->registerTriggerHandler($app->make(OnInsertTriggerHandler::class));
            $engine->registerTriggerHandler($app->make(OnUpdateTriggerHandler::class));
            $engine->registerTriggerHandler($app->make(OnDeleteTriggerHandler::class));
            $engine->registerTriggerHandler($app->make(TimeBasedTriggerHandler::class));
            $engine->registerTriggerHandler($app->make(ManualTriggerHandler::class));

            $engine->registerActionHandler($app->make(WebhookActionHandler::class));
            $engine->registerActionHandler($app->make(EmailActionHandler::class));
            $engine->registerActionHandler($app->make(VoidActionHandler::class));

            return $engine;
        });

        $this->app->alias(AutomationEngineContract::class, AutomationEngine::class);
    }
}
