<?php

declare(strict_types=1);

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
            'data' => ['id' => 'deploy-789', 'attributes' => ['status' => 'deployed']],
        ], 200),
        '*/deployments/deploy-789' => Http::response([
            'data' => ['id' => 'deploy-789', 'attributes' => ['status' => 'deployed']],
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
