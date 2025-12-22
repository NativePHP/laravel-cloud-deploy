<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy;

use Illuminate\Support\ServiceProvider;
use NativePhp\LaravelCloudDeploy\Commands\CloudDeployCommand;

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
                CloudDeployCommand::class,
            ]);
        }
    }
}
