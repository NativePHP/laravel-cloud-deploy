<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy;

use Illuminate\Support\ServiceProvider;
use NativePhp\LaravelCloudDeploy\Commands\CloudAppCommand;
use NativePhp\LaravelCloudDeploy\Commands\CloudDeployCommand;
use NativePhp\LaravelCloudDeploy\Commands\CloudStatusCommand;

class CloudDeployServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cloud.php',
            'cloud'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cloud.php' => config_path('cloud.php'),
            ], 'cloud-config');

            $this->commands([
                CloudAppCommand::class,
                CloudDeployCommand::class,
                CloudStatusCommand::class,
            ]);
        }
    }
}
