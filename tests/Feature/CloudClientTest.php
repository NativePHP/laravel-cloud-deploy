<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
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

test('hasValidToken is true when the organization endpoint answers', function () {
    Http::fake([
        'cloud.laravel.com/api/meta/organization' => Http::response([
            'data' => ['id' => 'org-1', 'type' => 'organizations', 'attributes' => ['name' => 'NativePHP', 'slug' => 'nativephp']],
        ]),
    ]);

    $client = new CloudClient('test-token');

    expect($client->hasValidToken())->toBeTrue()
        ->and($client->getOrganization()['data']['attributes']['slug'])->toBe('nativephp');
});

test('hasValidToken is false on a 401', function () {
    Http::fake([
        'cloud.laravel.com/api/meta/organization' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    expect((new CloudClient('bad-token'))->hasValidToken())->toBeFalse();
});

test('hasValidToken rethrows errors that are not about the token', function () {
    Http::fake([
        'cloud.laravel.com/api/meta/organization' => Http::response(['message' => 'Server Error'], 500),
    ]);

    (new CloudClient('test-token'))->hasValidToken();
})->throws(RequestException::class);

test('resource read methods hit the documented endpoints', function () {
    Http::fake([
        'cloud.laravel.com/api/*' => Http::response(['data' => []]),
    ]);

    $client = new CloudClient('test-token');

    $client->listRegions();
    $client->listCaches();
    $client->getCache('cache-1');
    $client->listCacheTypes();
    $client->listBuckets();
    $client->getBucket('bucket-1');
    $client->listBucketKeys('bucket-1');

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.$pair[0]->url())->all();

    expect($sent)->toBe([
        'GET https://cloud.laravel.com/api/meta/regions',
        'GET https://cloud.laravel.com/api/caches',
        'GET https://cloud.laravel.com/api/caches/cache-1',
        'GET https://cloud.laravel.com/api/caches/types',
        'GET https://cloud.laravel.com/api/buckets',
        'GET https://cloud.laravel.com/api/buckets/bucket-1',
        'GET https://cloud.laravel.com/api/buckets/bucket-1/keys',
    ]);
});

test('getEnvironmentDatabaseSchemaId reads the included database relationship', function () {
    Http::fake([
        'cloud.laravel.com/api/environments/env-1?include=database' => Http::response([
            'data' => [
                'id' => 'env-1',
                'relationships' => ['database' => ['data' => ['type' => 'databaseSchemas', 'id' => 'schema-1']]],
            ],
        ]),
        'cloud.laravel.com/api/environments/env-2?include=database' => Http::response([
            'data' => ['id' => 'env-2', 'relationships' => ['database' => ['data' => null]]],
        ]),
    ]);

    $client = new CloudClient('test-token');

    expect($client->getEnvironmentDatabaseSchemaId('env-1'))->toBe('schema-1')
        ->and($client->getEnvironmentDatabaseSchemaId('env-2'))->toBeNull();
});

test('attachDatabaseToEnvironment patches only database_schema_id', function () {
    Http::fake(['cloud.laravel.com/api/environments/env-1' => Http::response(['data' => ['id' => 'env-1']])]);

    (new CloudClient('test-token'))->attachDatabaseToEnvironment('env-1', 'schema-1');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->data() === ['database_schema_id' => 'schema-1']);
});

test('waitForDatabaseCluster polls until the cluster is no longer busy', function () {
    Http::fake([
        'cloud.laravel.com/api/databases/clusters/db-1' => Http::sequence()
            ->push(['data' => ['attributes' => ['status' => 'creating']]])
            ->push(['data' => ['attributes' => ['status' => 'creating']]])
            ->push(['data' => ['attributes' => ['status' => 'available']]]),
    ]);

    $statuses = [];

    $cluster = (new CloudClient('test-token'))->waitForDatabaseCluster(
        'db-1',
        pollIntervalSeconds: 0,
        onStatusChange: function (string $status) use (&$statuses) {
            $statuses[] = $status;
        },
    );

    expect($cluster['data']['attributes']['status'])->toBe('available')
        ->and($statuses)->toBe(['creating', 'available']);

    Http::assertSentCount(3);
});

test('allBackgroundProcesses and findDatabaseByName follow pagination links', function () {
    Http::fake([
        'cloud.laravel.com/api/instances/inst-1/background-processes?page=2' => Http::response([
            'data' => [['id' => 'process-2']],
        ]),
        'cloud.laravel.com/api/instances/inst-1/background-processes' => Http::response([
            'data' => [['id' => 'process-1']],
            'links' => ['next' => 'https://cloud.laravel.com/api/instances/inst-1/background-processes?page=2'],
        ]),
        'cloud.laravel.com/api/databases/clusters/db-1/databases?page=2' => Http::response([
            'data' => [['id' => 'schema-2', 'attributes' => ['name' => 'production']]],
        ]),
        'cloud.laravel.com/api/databases/clusters/db-1/databases' => Http::response([
            'data' => [['id' => 'schema-1', 'attributes' => ['name' => 'staging']]],
            'links' => ['next' => 'https://cloud.laravel.com/api/databases/clusters/db-1/databases?page=2'],
        ]),
    ]);

    $client = new CloudClient('test-token');

    expect(array_column($client->allBackgroundProcesses('inst-1'), 'id'))->toBe(['process-1', 'process-2'])
        ->and($client->findDatabaseByName('db-1', 'production')['id'])->toBe('schema-2')
        ->and($client->findDatabaseByName('db-1', 'missing'))->toBeNull();
});
