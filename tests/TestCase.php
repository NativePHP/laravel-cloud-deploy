<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Tests;

use NativePhp\LaravelCloudDeploy\CloudDeployServiceProvider;
use NativePhp\LaravelCloudDeploy\Migration\Support\Dns;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never hit real DNS from the tests.
        Dns::$resolver = fn (string $host) => [];
    }

    protected function getPackageProviders($app): array
    {
        return [
            CloudDeployServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Set up default test configuration
        $app['config']->set('cloud.token', null);
        $app['config']->set('cloud.application.repository', null);
        $app['config']->set('cloud.application.name', 'Test App');
        $app['config']->set('cloud.application.region', 'us-east-2');
        $app['config']->set('cloud.environments', []);
    }
}
