<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NativePhp\LaravelCloudDeploy\CloudClient;
use NativePhp\LaravelCloudDeploy\Enums\DeploymentStatus;

test('the client talks to the documented base URL', function () {
    Http::fake([
        'cloud.laravel.com/api/applications' => Http::response(['data' => []]),
    ]);

    (new CloudClient('test-token'))->listApplications();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://cloud.laravel.com/api/applications'
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('deleteApplication sends a DELETE request for the application', function () {
    Http::fake([
        'cloud.laravel.com/api/applications/app-1' => Http::response(null, 204),
    ]);

    $response = (new CloudClient('test-token'))->deleteApplication('app-1');

    expect($response->status())->toBe(204);

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://cloud.laravel.com/api/applications/app-1';
    });
});

test('find helpers follow pagination links', function () {
    Http::fake([
        'cloud.laravel.com/api/applications?page=2' => Http::response([
            'data' => [['id' => 'app-2', 'attributes' => ['repository' => ['full_name' => 'owner/two']]]],
            'links' => ['next' => null],
        ]),
        'cloud.laravel.com/api/applications' => Http::response([
            'data' => [['id' => 'app-1', 'attributes' => ['repository' => ['full_name' => 'owner/one']]]],
            'links' => ['next' => 'https://cloud.laravel.com/api/applications?page=2'],
        ]),
    ]);

    $app = (new CloudClient('test-token'))->findApplicationByRepository('owner/two');

    expect($app['id'])->toBe('app-2');

    Http::assertSentCount(2);
});

test('waitForDeployment returns on every terminal status', function (string $status) {
    Http::fake([
        'cloud.laravel.com/api/deployments/dep-1' => Http::response([
            'data' => ['attributes' => ['status' => $status]],
        ]),
    ]);

    $deployment = (new CloudClient('test-token'))->waitForDeployment('dep-1', timeoutSeconds: 5);

    expect($deployment['data']['attributes']['status'])->toBe($status);

    Http::assertSentCount(1);
})->with(['deployment.succeeded', 'deployment.failed', 'build.failed', 'failed', 'cancelled']);

test('waitForDeployment treats unknown stage failures as terminal', function () {
    Http::fake([
        'cloud.laravel.com/api/deployments/dep-1' => Http::response([
            'data' => ['attributes' => ['status' => 'release.failed']],
        ]),
    ]);

    $deployment = (new CloudClient('test-token'))->waitForDeployment('dep-1', timeoutSeconds: 5);

    expect($deployment['data']['attributes']['status'])->toBe('release.failed');
});

test('waitForDeployment keeps polling through intermediate statuses', function () {
    Http::fakeSequence('cloud.laravel.com/api/deployments/dep-1')
        ->push(['data' => ['attributes' => ['status' => 'build.running']]])
        ->push(['data' => ['attributes' => ['status' => 'build.succeeded']]])
        ->push(['data' => ['attributes' => ['status' => 'deployment.running']]])
        ->push(['data' => ['attributes' => ['status' => 'deployment.succeeded']]]);

    $seen = [];

    $deployment = (new CloudClient('test-token'))->waitForDeployment(
        'dep-1',
        timeoutSeconds: 15,
        pollIntervalSeconds: 0,
        onStatusChange: function (string $status) use (&$seen) {
            $seen[] = $status;
        },
    );

    expect($deployment['data']['attributes']['status'])->toBe('deployment.succeeded')
        ->and($seen)->toBe(['build.running', 'build.succeeded', 'deployment.running', 'deployment.succeeded']);

    Http::assertSentCount(4);
});

test('the deployment status enum knows which statuses are terminal', function () {
    $terminal = array_filter(DeploymentStatus::cases(), fn (DeploymentStatus $status) => $status->isTerminal());

    expect(array_map(fn (DeploymentStatus $status) => $status->value, array_values($terminal)))
        ->toEqualCanonicalizing(['deployment.succeeded', 'deployment.failed', 'build.failed', 'failed', 'cancelled'])
        ->and(DeploymentStatus::DeploymentSucceeded->isSuccessful())->toBeTrue()
        ->and(DeploymentStatus::BuildSucceeded->isTerminal())->toBeFalse()
        ->and(DeploymentStatus::isTerminalValue('deployed'))->toBeFalse();
});

test('getDeploymentLogs fetches the logs endpoint', function () {
    Http::fake([
        'cloud.laravel.com/api/deployments/dep-1/logs' => Http::response([
            'data' => ['build' => ['available' => true, 'steps' => []], 'deploy' => ['available' => false, 'steps' => []]],
            'meta' => ['deployment_status' => 'build.running'],
        ]),
    ]);

    $logs = (new CloudClient('test-token'))->getDeploymentLogs('dep-1');

    expect($logs['meta']['deployment_status'])->toBe('build.running');
});

test('setEnvironmentVariables chunks requests at 200 variables', function () {
    Http::fake([
        'cloud.laravel.com/api/environments/env-1/variables' => Http::response(['data' => []]),
    ]);

    $variables = array_map(fn (int $i) => ['key' => "KEY_{$i}", 'value' => (string) $i], range(1, 450));

    (new CloudClient('test-token'))->setEnvironmentVariables('env-1', $variables);

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);

    expect($sent)->toHaveCount(3)
        ->and($sent->map(fn (Request $request) => count($request['variables']))->all())->toBe([200, 200, 50])
        ->and($sent->pluck('method')->unique()->all())->toBe(['set'])
        ->and($sent[2]['variables'][49])->toBe(['key' => 'KEY_450', 'value' => '450']);
});

test('addEnvironmentVariables uses the append method', function () {
    Http::fake([
        'cloud.laravel.com/api/environments/env-1/variables' => Http::response(['data' => []]),
    ]);

    (new CloudClient('test-token'))->addEnvironmentVariables('env-1', [['key' => 'FOO', 'value' => 'bar']]);

    Http::assertSent(fn (Request $request) => $request['method'] === 'append'
        && $request['variables'] === [['key' => 'FOO', 'value' => 'bar']]);
});

test('storing an empty variable list sends nothing', function () {
    Http::fake();

    (new CloudClient('test-token'))->setEnvironmentVariables('env-1', []);
    (new CloudClient('test-token'))->deleteEnvironmentVariables('env-1', []);

    Http::assertNothingSent();
});

test('deleteEnvironmentVariables posts the keys to the delete endpoint', function () {
    Http::fake([
        'cloud.laravel.com/api/environments/env-1/variables/delete' => Http::response(['data' => []]),
    ]);

    (new CloudClient('test-token'))->deleteEnvironmentVariables('env-1', ['FOO', 'BAR']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cloud.laravel.com/api/environments/env-1/variables/delete'
        && $request['keys'] === ['FOO', 'BAR']);
});

test('database cluster methods use the clusters endpoints', function () {
    Http::fake([
        'cloud.laravel.com/api/*' => Http::response(['data' => []]),
    ]);

    $client = new CloudClient('test-token');

    $client->listDatabaseClusters();
    $client->getDatabaseCluster('db-1');
    $client->updateDatabaseCluster('db-1', ['config' => ['is_public' => true]]);
    $client->deleteDatabaseCluster('db-1');
    $client->listDatabases('db-1');
    $client->createDatabase('db-1', 'main');
    $client->listDatabaseTypes();

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.$pair[0]->url())->all();

    expect($sent)->toBe([
        'GET https://cloud.laravel.com/api/databases/clusters',
        'GET https://cloud.laravel.com/api/databases/clusters/db-1',
        'PATCH https://cloud.laravel.com/api/databases/clusters/db-1',
        'DELETE https://cloud.laravel.com/api/databases/clusters/db-1',
        'GET https://cloud.laravel.com/api/databases/clusters/db-1/databases',
        'POST https://cloud.laravel.com/api/databases/clusters/db-1/databases',
        'GET https://cloud.laravel.com/api/databases/types',
    ]);
});

test('createDatabaseCluster posts to the clusters endpoint', function () {
    Http::fake([
        'cloud.laravel.com/api/databases/clusters' => Http::response(['data' => ['id' => 'db-1']], 201),
    ]);

    (new CloudClient('test-token'))->createDatabaseCluster([
        'type' => 'laravel_mysql',
        'version' => '8.0',
        'name' => 'main',
        'region' => 'us-east-2',
        'config' => ['size' => 'mysql-flex-512mb'],
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cloud.laravel.com/api/databases/clusters'
        && $request['type'] === 'laravel_mysql');
});
