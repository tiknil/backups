<?php

namespace Tiknil\Backups;

use Illuminate\Support\ServiceProvider;
use Tiknil\Backups\Commands\BackupChecks;
use Tiknil\Backups\Commands\BackupRestore;
use Tiknil\Backups\Commands\DailyBackup;
use Tiknil\Backups\Commands\HoldFiles;

class BackupsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'backups');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'backups');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tk-backups.php' => config_path('tk-backups.php'),
            ], 'tk-backups:config');
        }

        $this->commands([
            DailyBackup::class,
            BackupChecks::class,
            BackupRestore::class,
            HoldFiles::class,
        ]);
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/tk-backups.php', 'tk-backups');

        // Register the main class to use with the facade
        $this->app->singleton('backups', function () {
            return new Backups;
        });
    }
}
