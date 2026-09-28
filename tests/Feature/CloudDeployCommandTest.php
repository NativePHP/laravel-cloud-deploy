<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        ->assertExitCode(0);
});

/**
 * Config and API routes for one environment with an App instance and a queue worker.
 *
 * @param  array<int, array<string, mixed>>  $processes  What the API lists on the instance
 * @return array<string, mixed>
 */
function workerRoutes(array $processes): array
{
    config([
        'cloud.token' => 'test-token',
        'cloud.application.repository' => 'owner/repo',
        'cloud.variables' => [],
        'cloud.databases' => [],
        'cloud.environments' => [
            'production' => [
                'branch' => 'main',
                'instances' => [
                    'App' => [
                        'type' => 'app',
                        'scaling' => ['type' => 'none'],
                        'processes' => [
                            'queue-worker' => [
                                'type' => 'worker',
                                'processes' => 1,
                                'queue' => [
                                    'connection' => 'database',
                                    'queues' => ['default'],
                                    'tries' => 3,
                                    'backoff' => 60,
                                    'timeout' => 120,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    return [
        'GET /applications' => ['data' => [['id' => 'app-1', 'attributes' => ['repository' => ['full_name' => 'owner/repo']]]]],
        'GET /applications/app-1/environments' => ['data' => [['id' => 'env-1', 'attributes' => ['name' => 'production']]]],
        'GET /environments/env-1/instances' => ['data' => [['id' => 'inst-1', 'attributes' => ['name' => 'App']]]],
        'GET /instances/inst-1/background-processes' => ['data' => $processes],
        'POST /instances/inst-1/background-processes' => Http::response(['data' => ['id' => 'process-new']], 201),
        'POST /environments/env-1/deployments' => Http::response(['data' => ['id' => 'dep-1']], 201),
        'GET /deployments/dep-1' => ['data' => ['id' => 'dep-1', 'attributes' => ['status' => 'deployment.succeeded']]],
    ];
}

/**
 * A worker as the API lists it, with the settings workerRoutes() configures.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listedWorker(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'type' => 'backgroundProcesses',
        'attributes' => array_replace_recursive([
            'type' => 'worker',
            'processes' => 1,
            'command' => 'php artisan queue:work database --queue=default --tries=3 --backoff=60 --sleep=3 --rest=0 --timeout=120 --quiet',
            'config' => [
                'connection' => 'database',
                'queue' => 'default',
                'tries' => 3,
                'backoff' => 60,
                'sleep' => 3,
                'rest' => 0,
                'timeout' => 120,
                'force' => false,
            ],
            'strategy_type' => 'none',
            'strategy_threshold' => null,
        ], $overrides),
    ];
}

function isProcessWrite(Request $request): bool
{
    return isWrite($request) && str_contains($request->url(), 'background-processes');
}

test('dry run never writes the state file, even when lookups find new IDs', function () {
    $path = base_path('.laravel-cloud-test.json');
    file_put_contents($path, json_encode(['application_id' => 'app-1'], JSON_PRETTY_PRINT));
    touch($path, $mtime = time() - 3600);
    $before = file_get_contents($path);

    fakeCloudApi(workerRoutes([]));

    $this->artisan('cloud:deploy', ['--dry-run' => true])
        ->expectsOutputToContain('Found existing environment: env-1')
        ->expectsOutputToContain('Found existing instance App: inst-1')
        ->expectsOutputToContain('[DRY RUN] .laravel-cloud-test.json would be updated with the IDs found above. It was not written.')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => isWrite($request));

    clearstatcache();
    expect(file_get_contents($path))->toBe($before)
        ->and(filemtime($path))->toBe($mtime);
});

test('a second run that finds nothing new does not write the state file', function () {
    $path = base_path('.laravel-cloud-test.json');

    fakeCloudApi(workerRoutes([listedWorker('process-1')]));

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->expectsOutputToContain('Updated .laravel-cloud-test.json.')
        ->assertExitCode(0);

    $before = file_get_contents($path);
    touch($path, $mtime = time() - 3600);

    // A full deploy this time: the deployment ID isn't written either.
    $this->artisan('cloud:deploy', ['--force' => true])
        ->expectsOutputToContain('Deployment initiated: dep-1')
        ->expectsOutputToContain('.laravel-cloud-test.json is unchanged.')
        ->assertExitCode(0);

    clearstatcache();
    expect(file_get_contents($path))->toBe($before)
        ->and(filemtime($path))->toBe($mtime)
        ->and($before)->not->toContain('updated_at')
        ->and($before)->not->toContain('dep-1');
});

test('cloud state only writes the file when an ID changes', function () {
    $path = base_path('.laravel-cloud-test.json');

    $state = new CloudState($path);
    expect($state->save())->toBeFalse()
        ->and(file_exists($path))->toBeFalse();

    $state->setApplicationId('app-1');
    expect($state->save())->toBeTrue();

    $state->setApplicationId('app-1');
    expect($state->isDirty())->toBeFalse()
        ->and($state->save())->toBeFalse();

    $state->setEnvironmentId('production', 'env-1');
    expect($state->save())->toBeTrue();
});

test('old timestamps and deployment IDs only drop out of the state file on a real change', function () {
    $path = base_path('.laravel-cloud-test.json');
    $legacy = json_encode([
        'application_id' => 'app-1',
        'environments' => ['production' => ['id' => 'env-1', 'last_deployment_id' => 'dep-old']],
        'updated_at' => '2026-01-01T00:00:00+00:00',
    ], JSON_PRETTY_PRINT);
    file_put_contents($path, $legacy);

    $state = new CloudState($path);
    $state->setApplicationId('app-1');
    expect($state->save())->toBeFalse()
        ->and(file_get_contents($path))->toBe($legacy);

    $state->setInstanceId('production', 'App', 'inst-1');
    expect($state->save())->toBeTrue();

    expect(json_decode(file_get_contents($path), true))->toBe([
        'application_id' => 'app-1',
        'environments' => ['production' => ['id' => 'env-1', 'instances' => ['App' => ['id' => 'inst-1']]]],
    ]);
});
