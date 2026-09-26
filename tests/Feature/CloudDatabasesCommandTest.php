<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

test('cluster details list the databases from the clusters endpoint', function () {
    config(['cloud.token' => 'test-token']);

    Http::fake([
        'cloud.laravel.com/api/databases/clusters/db-1/databases' => Http::response([
            'data' => [
                ['id' => 'schema-1', 'type' => 'databaseSchemas', 'attributes' => ['name' => 'main']],
            ],
        ]),
        'cloud.laravel.com/api/databases/clusters/db-1' => Http::response([
            'data' => [
                'id' => 'db-1',
                'attributes' => [
                    'name' => 'primary',
                    'type' => 'laravel_mysql',
                    'status' => 'available',
                    'region' => 'us-east-2',
                    'config' => [],
                    'connection' => ['hostname' => 'db.example.test', 'port' => 3306],
                ],
            ],
        ]),
        'cloud.laravel.com/api/databases/clusters' => Http::response([
            'data' => [['id' => 'db-1', 'attributes' => ['name' => 'primary']]],
        ]),
    ]);

    $this->artisan('cloud:databases', ['--cluster' => 'primary'])
        ->expectsOutputToContain('Laravel MySQL')
        ->expectsOutputToContain('db.example.test')
        ->expectsOutputToContain('main')
        ->assertExitCode(0);
});
