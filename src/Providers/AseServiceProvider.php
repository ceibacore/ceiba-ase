<?php

namespace LemurAse\Providers;

use Illuminate\Support\ServiceProvider;
use LemurAse\AseManager;

class AseServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the AseManager as a singleton in the Laravel container
        $this->app->singleton('ase', function ($app) {
            return AseManager::getInstance();
        });

        // Also bind the class name directly
        $this->app->singleton(AseManager::class, function ($app) {
            return AseManager::getInstance();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration if needed in the future
        // $this->publishes([
        //     __DIR__.'/../../config/ase.php' => config_path('ase.php'),
        // ], 'ase-config');

        // Note: The host application should set up the environment variables
        // ASE_DB_HOST, ASE_DB_PORT, ASE_DB_NAME, etc., in its .env file.
    }
}
