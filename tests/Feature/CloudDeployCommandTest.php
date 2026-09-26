<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\CloudState;

beforeEach(function () {
    // Use a test-specific state file to avoid interfering with real state
    $testStatePath = base_path('.laravel-cloud-test.json');
    config(['cloud.state_path' => $testStatePath]);

    // Clean up any existing test state file
    if (file_exists($testStatePath)) {
        unlink($testStatePath);
    }
});

afterEach(function () {
    // Clean up test state file after tests
    $testStatePath = base_path('.laravel-cloud-test.json');
    if (file_exists($testStatePath)) {
        unlink($testStatePath);
    }
});

test('command fails when token is not configured', function () {
    config(['cloud.token' => null]);
    config(['cloud.application.repository' => 'owner/repo']);

    $this->artisan('cloud:deploy')
        ->expectsOutput('LARAVEL_CLOUD_TOKEN is not set in your .env file.')
        ->assertExitCode(1);
});

test('command fails when repository is not configured', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => null]);

    $this->artisan('cloud:deploy')
        ->expectsOutput('LARAVEL_CLOUD_REPOSITORY is not set in your .env file.')
        ->assertExitCode(1);
});

test('command fails when no environments are configured', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.environments' => []]);

    $this->artisan('cloud:deploy')
        ->expectsOutput('No environments configured in config/cloud.php.')
        ->assertExitCode(1);
});

test('command fails for unknown environment', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.environments' => [
        'production' => ['branch' => 'main'],
    ]]);

    Http::fake([
        '*/applications' => Http::response(['data' => []], 200),
    ]);

    $this->artisan('cloud:deploy', ['environment' => 'unknown'])
        ->assertExitCode(1);
});

test('dry run shows what would be done without making changes', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.application.name' => 'Test App']);
    config(['cloud.application.region' => 'us-east-2']);
    config(['cloud.environments' => [
        'production' => [
            'branch' => 'main',
            'php' => '8.4',
        ],
    ]]);

    Http::fake([
        '*/applications' => Http::response(['data' => []], 200),
    ]);

    $this->artisan('cloud:deploy', ['--dry-run' => true, '--force' => true])
        ->expectsOutputToContain('[DRY RUN]')
        ->assertExitCode(0);

    // Verify no state file was created
    expect(file_exists(base_path('.laravel-cloud-test.json')))->toBeFalse();
});

test('command finds existing application by repository', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.application.name' => 'Test App']);
    config(['cloud.environments' => [
        'production' => ['branch' => 'main'],
    ]]);

    Http::fake([
        '*/applications' => Http::response([
            'data' => [
                [
                    'id' => 'app-123',
                    'attributes' => [
                        'repository' => ['full_name' => 'owner/repo'],
                    ],
                ],
            ],
        ], 200),
        '*/applications/app-123/environments' => Http::response([
            'data' => [
                [
                    'id' => 'env-456',
                    'attributes' => ['name' => 'production'],
                ],
            ],
        ], 200),
        '*/environments/env-456' => Http::response(['data' => ['id' => 'env-456']], 200),
        '*/environments/env-456/instances' => Http::response(['data' => []], 200),
        '*/environments/env-456/domains' => Http::response(['data' => []], 200),
        '*/environments/env-456/variables' => Http::response(['data' => []], 200),
        '*/environments/env-456/deployments' => Http::response([
            'data' => ['id' => 'deploy-789', 'attributes' => ['status' => 'deployment.succeeded']],
        ], 200),
        '*/deployments/deploy-789' => Http::response([
            'data' => ['id' => 'deploy-789', 'attributes' => ['status' => 'deployment.succeeded']],
        ], 200),
    ]);

    $this->artisan('cloud:deploy', ['--force' => true])
        ->expectsOutputToContain('Found existing application: app-123')
        ->assertExitCode(0);
});

test('cloud state saves and loads application id', function () {
    $state = new CloudState(base_path('.laravel-cloud-test.json'));

    $state->setApplicationId('app-123');
    $state->save();

    $loadedState = new CloudState(base_path('.laravel-cloud-test.json'));

    expect($loadedState->getApplicationId())->toBe('app-123');

    // Cleanup
    unlink(base_path('.laravel-cloud-test.json'));
});

test('cloud state saves and loads environment ids', function () {
    $state = new CloudState(base_path('.laravel-cloud-test.json'));

    $state->setEnvironmentId('production', 'env-123');
    $state->setEnvironmentId('staging', 'env-456');
    $state->save();

    $loadedState = new CloudState(base_path('.laravel-cloud-test.json'));

    expect($loadedState->getEnvironmentId('production'))->toBe('env-123');
    expect($loadedState->getEnvironmentId('staging'))->toBe('env-456');
    expect($loadedState->getEnvironmentId('unknown'))->toBeNull();

    // Cleanup
    unlink(base_path('.laravel-cloud-test.json'));
});

test('cloud state saves and loads instance ids', function () {
    $state = new CloudState(base_path('.laravel-cloud-test.json'));

    $state->setInstanceId('production', 'web', 'instance-123');
    $state->save();

    $loadedState = new CloudState(base_path('.laravel-cloud-test.json'));

    expect($loadedState->getInstanceId('production', 'web'))->toBe('instance-123');
    expect($loadedState->getInstanceId('production', 'unknown'))->toBeNull();

    // Cleanup
    unlink(base_path('.laravel-cloud-test.json'));
});

test('cloud state saves and loads process ids', function () {
    $state = new CloudState(base_path('.laravel-cloud-test.json'));

    $state->setProcessId('production', 'web', 'default-worker', 'process-123');
    $state->save();

    $loadedState = new CloudState(base_path('.laravel-cloud-test.json'));

    expect($loadedState->getProcessId('production', 'web', 'default-worker'))->toBe('process-123');

    // Cleanup
    unlink(base_path('.laravel-cloud-test.json'));
});

test('skip-deploy option configures without deploying', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.application.name' => 'Test App']);
    config(['cloud.environments' => [
        'production' => ['branch' => 'main'],
    ]]);

    Http::fake([
        '*/applications' => Http::response([
            'data' => [
                [
                    'id' => 'app-123',
                    'attributes' => [
                        'repository' => ['full_name' => 'owner/repo'],
                    ],
                ],
            ],
        ], 200),
        '*/applications/app-123/environments' => Http::response([
            'data' => [
                [
                    'id' => 'env-456',
                    'attributes' => ['name' => 'production'],
                ],
            ],
        ], 200),
        '*/environments/env-456' => Http::response(['data' => ['id' => 'env-456']], 200),
        '*/environments/env-456/instances' => Http::response(['data' => []], 200),
        '*/environments/env-456/domains' => Http::response(['data' => []], 200),
        '*/environments/env-456/variables' => Http::response(['data' => []], 200),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->doesntExpectOutputToContain('Initiating deployment')
        ->assertExitCode(0);
});

test('new applications are created with a source control provider', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.application.name' => 'Test App']);
    config(['cloud.application.region' => 'eu-west-2']);
    config(['cloud.environments' => ['production' => ['branch' => 'main']]]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications')) {
            return Http::response(['data' => []]);
        }

        if ($request->method() === 'POST' && str_ends_with($request->url(), '/applications')) {
            return Http::response(['data' => ['id' => 'app-new']], 201);
        }

        if (str_ends_with($request->url(), '/applications/app-new/environments')) {
            return $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 'env-new']], 201);
        }

        return Http::response(['data' => []]);
    });

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/applications')
        && $request['source_control_provider_type'] === 'github'
        && $request['repository'] === 'owner/repo');
});

test('deploy sends spec-shaped environment, variable, instance and domain payloads', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.variables' => ['global' => ['APP_DEBUG' => 'false'], 'production' => ['APP_ENV' => 'production']]]);
    config(['cloud.environments' => [
        'production' => [
            'branch' => 'main',
            'timeout' => 45,
            'octane' => true,
            'network' => [
                'firewall' => ['block_path' => true, 'browser_integrity_check' => true],
            ],
            'instances' => [
                'App' => [
                    'type' => 'app',
                    'size' => 'flex-1gb',
                    'scaling' => ['type' => 'auto', 'min_replicas' => 1, 'max_replicas' => 3],
                    'hibernation_timeout' => null,
                ],
                'worker' => [
                    'type' => 'service',
                    'size' => 'flex-512mb',
                    'scaling' => ['type' => 'custom', 'min_replicas' => 1, 'max_replicas' => 2],
                ],
            ],
            'domains' => [
                'example.com' => ['www_redirect' => 'www_to_root'],
                'new.example.com' => ['wildcard' => true],
            ],
        ],
    ]]);

    Http::fake([
        '*/applications' => Http::response([
            'data' => [['id' => 'app-1', 'attributes' => ['repository' => ['full_name' => 'owner/repo']]]],
        ]),
        '*/applications/app-1/environments' => Http::response([
            'data' => [['id' => 'env-1', 'attributes' => ['name' => 'production']]],
        ]),
        '*/environments/env-1/instances' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => [['id' => 'inst-app', 'attributes' => ['name' => 'App']]]])
                : Http::response(['data' => ['id' => 'inst-worker']], 201);
        },
        '*/environments/env-1/domains' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => [['id' => 'dom-1', 'attributes' => ['name' => 'example.com']]]])
                : Http::response(['data' => ['id' => 'dom-2']], 201);
        },
        '*' => Http::response(['data' => []]),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/environments/env-1')
        && $request['timeout'] === 45
        && $request['uses_octane'] === true
        && $request['firewall_block_path'] === true
        && ! isset($request['uses_web_server'])
        && ! isset($request['sleep_timeout']));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/environments/env-1/variables')
        && $request['method'] === 'set'
        && count($request['variables']) === 2);

    // Existing app instance: no type, no replica counts with auto scaling.
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/instances/inst-app')
        && $request['scaling_type'] === 'auto'
        && ! isset($request['type'])
        && ! isset($request['min_replicas'])
        && array_key_exists('hibernation_timeout', $request->data())
        && $request['hibernation_timeout'] === null);

    // New worker instance: type plus the nullable fields the API requires.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/environments/env-1/instances')
        && $request['type'] === 'service'
        && $request['scaling_type'] === 'custom'
        && $request['max_replicas'] === 2
        && array_key_exists('visibility_timeout', $request->data())
        && array_key_exists('shutdown_timeout', $request->data()));

    // Existing domain isn't patched; new domain is created.
    Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/domains/'));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/environments/env-1/domains')
        && $request['name'] === 'new.example.com'
        && $request['wildcard_enabled'] === true
        && ! array_key_exists('www_redirect', $request->data()));
});

test('deploy reports failure for a cancelled deployment', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.variables' => []]);
    config(['cloud.environments' => ['production' => ['branch' => 'main']]]);

    Http::fake([
        '*/applications' => Http::response([
            'data' => [['id' => 'app-1', 'attributes' => ['repository' => ['full_name' => 'owner/repo']]]],
        ]),
        '*/applications/app-1/environments' => Http::response([
            'data' => [['id' => 'env-1', 'attributes' => ['name' => 'production']]],
        ]),
        '*/environments/env-1/deployments' => Http::response(['data' => ['id' => 'dep-1']], 201),
        '*/deployments/dep-1' => Http::response([
            'data' => ['id' => 'dep-1', 'attributes' => ['status' => 'cancelled', 'failure_reason' => 'Cancelled by user']],
        ]),
        '*' => Http::response(['data' => []]),
    ]);

    $this->artisan('cloud:deploy', ['--force' => true])
        ->expectsOutputToContain('Deployment failed: Cancelled by user')
        ->assertExitCode(1);
});

/**
 * Fake the Cloud API for an existing app-1/env-1 plus whatever resource
 * routes a test needs.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeCloudWithEnvironment(array $routes = []): void
{
    Http::fake(array_merge([
        '*/applications' => Http::response([
            'data' => [['id' => 'app-1', 'attributes' => ['repository' => ['full_name' => 'owner/repo']]]],
        ]),
        '*/applications/app-1/environments' => Http::response([
            'data' => [['id' => 'env-1', 'attributes' => ['name' => 'production']]],
        ]),
    ], $routes, ['*' => Http::response(['data' => []])]));
}

test('deploy creates and attaches a database cluster, cache and bucket', function () {
    Sleep::fake();

    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.variables' => []]);
    config(['cloud.environments' => ['production' => ['branch' => 'main']]]);
    config(['cloud.databases' => [
        'app-db' => [
            'type' => 'laravel_mysql',
            'region' => 'eu-west-2',
            'config' => ['size' => 'mysql-flex-512mb', 'storage' => 5, 'is_public' => false, 'uses_scheduled_snapshots' => true, 'retention_days' => 7],
            'environments' => ['production' => 'laravel'],
        ],
    ]]);
    config(['cloud.caches' => [
        'app-cache' => ['type' => 'laravel_valkey', 'size' => 'valkey-flex-250mb', 'region' => 'eu-west-2', 'environments' => ['production']],
    ]]);
    config(['cloud.buckets' => [
        'app-files' => ['visibility' => 'public', 'disk' => 'public', 'default' => false, 'environments' => ['production']],
    ]]);

    $clusterPolls = 0;

    fakeCloudWithEnvironment([
        '*/databases/types' => Http::response(['data' => [
            ['type' => 'laravel_mysql', 'versions' => ['8.0', '8.4'], 'regions' => [], 'config_schema' => []],
        ]]),
        '*/databases/clusters/db-1/databases' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 'schema-1', 'attributes' => ['name' => 'laravel']]], 201);
        },
        '*/databases/clusters/db-1' => function () use (&$clusterPolls) {
            $clusterPolls++;

            return Http::response(['data' => ['id' => 'db-1', 'attributes' => [
                'status' => $clusterPolls > 1 ? 'available' : 'creating',
            ]]]);
        },
        '*/databases/clusters' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 'db-1']], 201);
        },
        '*/caches/cache-1' => Http::response(['data' => ['id' => 'cache-1', 'attributes' => ['status' => 'available']]]),
        '*/caches' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 'cache-1']], 201);
        },
        '*/buckets' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response([
                    'data' => ['id' => 'bucket-1', 'relationships' => ['keys' => ['data' => [['type' => 'filesystemKeys', 'id' => 'key-1']]]]],
                    'included' => [['id' => 'key-1', 'type' => 'filesystemKeys']],
                ], 201);
        },
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases/clusters')
        && $request['type'] === 'laravel_mysql'
        && $request['version'] === '8.4'
        && $request['name'] === 'app-db'
        && $request['region'] === 'eu-west-2'
        && $request['create_default_database'] === false
        && $request['config']['size'] === 'mysql-flex-512mb');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases/clusters/db-1/databases')
        && $request['name'] === 'laravel');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/caches')
        && $request['type'] === 'laravel_valkey'
        && $request['is_public'] === false);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/buckets')
        && $request['visibility'] === 'public'
        && $request['key_permission'] === 'read_write');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/environments/env-1')
        && ($request->data()['database_schema_id'] ?? null) === 'schema-1'
        && ($request->data()['cache_id'] ?? null) === 'cache-1'
        && ($request->data()['filesystem_keys'] ?? null) === [['id' => 'key-1', 'disk' => 'public', 'is_default_disk' => false]]);

    $state = new CloudState(base_path('.laravel-cloud-test.json'));
    expect($state->get('resources.databases.app-db.id'))->toBe('db-1')
        ->and($state->get('resources.databases.app-db.schemas.laravel'))->toBe('schema-1')
        ->and($state->get('resources.caches.app-cache.id'))->toBe('cache-1')
        ->and($state->get('resources.buckets.app-files.key_id'))->toBe('key-1');
});

test('deploy reuses resources recorded in the state file', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.variables' => []]);
    config(['cloud.environments' => ['production' => ['branch' => 'main']]]);
    config(['cloud.databases' => ['app-db' => ['type' => 'neon_serverless_postgres', 'environments' => ['production']]]]);
    config(['cloud.caches' => ['app-cache' => ['environments' => ['production']]]]);

    $state = new CloudState(base_path('.laravel-cloud-test.json'));
    $state->set('resources.databases.app-db', ['id' => 'db-1', 'schemas' => ['production' => 'schema-1']]);
    $state->set('resources.caches.app-cache.id', 'cache-1');
    $state->save();

    fakeCloudWithEnvironment();

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
        && (str_contains($request->url(), '/databases') || str_contains($request->url(), '/caches')));

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/environments/env-1')
        && ($request->data()['database_schema_id'] ?? null) === 'schema-1'
        && ($request->data()['cache_id'] ?? null) === 'cache-1');
});

test('deploy exits non-zero when a deployment fails', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.variables' => []]);
    config(['cloud.environments' => ['production' => ['branch' => 'main']]]);

    fakeCloudWithEnvironment([
        '*/environments/env-1/deployments' => Http::response(['data' => ['id' => 'dep-1']], 201),
        '*/deployments/dep-1' => Http::response([
            'data' => ['id' => 'dep-1', 'attributes' => ['status' => 'build.failed', 'failure_reason' => 'composer install failed']],
        ]),
    ]);

    $this->artisan('cloud:deploy', ['--force' => true])
        ->expectsOutputToContain('Deployment failed for: production')
        ->assertExitCode(1);
});

test('managed queue instances are created with a minimum of zero replicas', function () {
    config(['cloud.token' => 'test-token']);
    config(['cloud.application.repository' => 'owner/repo']);
    config(['cloud.variables' => []]);
    config(['cloud.environments' => ['production' => [
        'branch' => 'main',
        'instances' => [
            'emails' => ['type' => 'managed_queue', 'size' => 'mq.flex.256mb', 'scaling' => ['type' => 'custom', 'max_replicas' => 3]],
        ],
    ]]]);

    fakeCloudWithEnvironment([
        '*/environments/env-1/instances' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 'inst-q']], 201);
        },
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/environments/env-1/instances')
        && $request['name'] === 'emails'
        && $request['type'] === 'managed_queue'
        && $request['size'] === 'mq.flex.256mb'
        && $request['min_replicas'] === 0
        && $request['max_replicas'] === 3);
});
