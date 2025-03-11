<?php

namespace App\Providers;

use App\Services\Database\ConnectionFactory;
use App\Services\Database\DatabaseConnectionResolver;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Storage\AssetService;
use App\Services\Storage\StorageConfigResolver;
use App\Services\Storage\StorageFactory;
use App\Services\Storage\StorageService;
use Illuminate\Support\ServiceProvider;

class StorageServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->setupStorageServices();
        $this->setupDatabaseServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    protected function setupStorageServices(): void
    {
        $this->app->singleton(StorageConfigResolver::class);
        $this->app->singleton(StorageFactory::class, function ($app) {
            return new StorageFactory(
                $app->make(StorageConfigResolver::class)
            );
        });
        $this->app->singleton(StorageService::class, function ($app) {
            return new StorageService(
                $app->make(StorageFactory::class)
            );
        });
        $this->app->singleton(AssetService::class, function ($app) {
            return new AssetService(
                $app->make(\App\Services\Storage\StorageService::class)
            );
        });
    }

    protected function setupDatabaseServices()
    {
        $this->app->singleton(DatabaseConnectionResolver::class);
        $this->app->singleton(ConnectionFactory::class, function ($app) {
            return new ConnectionFactory(
                $app->make(DatabaseConnectionResolver::class)
            );
        });
        $this->app->singleton(DatabaseConnectionService::class, function ($app) {
            return new DatabaseConnectionService(
                $app->make(ConnectionFactory::class)
            );
        });

    }
}
