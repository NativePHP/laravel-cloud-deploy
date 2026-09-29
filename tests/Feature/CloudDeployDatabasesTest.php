<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->statePath = base_path('.laravel-cloud-test.json');
    config(['cloud.state_path' => $this->statePath]);

    if (file_exists($this->statePath)) {
        unlink($this->statePath);
    }

    config([
        'cloud.token' => 'test-token',
        'cloud.application.repository' => 'owner/repo',
        'cloud.application.region' => 'eu-central-1',
        'cloud.variables' => [],
        'cloud.environments' => ['production' => ['branch' => 'main']],
        'cloud.databases' => [
            'kickback' => [
                'type' => 'laravel_mysql',
                'version' => '8.4',
                'region' => 'eu-central-1',
                'config' => [
                    'size' => 'mysql-flex-512mb',
                    'storage' => 5,
                    'is_public' => false,
                    'uses_scheduled_snapshots' => true,
                    'retention_days' => 1,
                ],
                'environments' => ['production'],
            ],
        ],
    ]);

    $this->baseRoutes = [
        'GET /applications' => ['data' => [['id' => 'app-1', 'attributes' => ['repository' => ['full_name' => 'owner/repo']]]]],
        'GET /applications/app-1/environments' => ['data' => [['id' => 'env-1', 'attributes' => ['name' => 'production']]]],
    ];
});

afterEach(function () {
    if (file_exists($this->statePath)) {
        unlink($this->statePath);
    }
});

/**
 * An environment response with the given database attached.
 *
 * @return array<string, mixed>
 */
function environmentWithDatabase(?string $schemaId): array
{
    return [
        'data' => [
            'id' => 'env-1',
            'type' => 'environments',
            'attributes' => ['name' => 'production'],
            'relationships' => [
                'database' => ['data' => $schemaId ? ['type' => 'databaseSchemas', 'id' => $schemaId] : null],
            ],
        ],
    ];
}

function isAttach(Request $request, ?string $schemaId = null): bool
{
    return $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/environments/env-1')
        && array_key_exists('database_schema_id', $request->data())
        && ($schemaId === null || $request['database_schema_id'] === $schemaId);
}

test('deploy finds the cluster and database by name and attaches the database', function () {
    fakeCloudApi($this->baseRoutes + [
        'GET /databases/clusters' => ['data' => [
            ['id' => 'db-other', 'attributes' => ['name' => 'someone-else']],
            ['id' => 'db-1', 'attributes' => ['name' => 'kickback', 'status' => 'available']],
        ]],
        'GET /databases/clusters/db-1/databases' => ['data' => [
            ['id' => 'schema-other', 'attributes' => ['name' => 'staging']],
            ['id' => 'schema-1', 'attributes' => ['name' => 'production']],
        ]],
        'GET /environments/env-1' => environmentWithDatabase(null),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->expectsOutputToContain('Found existing database cluster kickback: db-1')
        ->expectsOutputToContain('Found existing database production: schema-1')
        ->expectsOutputToContain('Attached database kickback/production.')
        ->assertExitCode(0);

    // The attach is its own PATCH, carrying nothing but the schema ID.
    Http::assertSent(fn (Request $request) => isAttach($request, 'schema-1')
        && $request->data() === ['database_schema_id' => 'schema-1']);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/environments/env-1?include=database'));

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/databases'));

    $state = json_decode(file_get_contents($this->statePath), true);

    expect($state['databases']['kickback']['id'])->toBe('db-1')
        ->and($state['databases']['kickback']['schemas']['production']['id'])->toBe('schema-1');
});

test('deploy creates a missing cluster with the configured settings, then the database, then attaches it', function () {
    fakeCloudApi($this->baseRoutes + [
        'GET /databases/clusters' => ['data' => [['id' => 'db-other', 'attributes' => ['name' => 'someone-else']]]],
        'POST /databases/clusters' => Http::response(['data' => ['id' => 'db-new', 'attributes' => ['status' => 'creating']]], 201),
        'GET /databases/clusters/db-new' => ['data' => ['id' => 'db-new', 'attributes' => ['name' => 'kickback', 'status' => 'available']]],
        'GET /databases/clusters/db-new/databases' => ['data' => []],
        'POST /databases/clusters/db-new/databases' => Http::response(['data' => ['id' => 'schema-new', 'attributes' => ['name' => 'production']]], 201),
        'GET /environments/env-1' => environmentWithDatabase(null),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->expectsOutputToContain('Created database cluster: db-new')
        ->expectsOutputToContain('Created database: schema-new')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases/clusters')
        && $request->data() === [
            'name' => 'kickback',
            'type' => 'laravel_mysql',
            'version' => '8.4',
            'region' => 'eu-central-1',
            'config' => [
                'size' => 'mysql-flex-512mb',
                'storage' => 5,
                'is_public' => false,
                'uses_scheduled_snapshots' => true,
                'retention_days' => 1,
            ],
            'create_default_database' => false,
        ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases/clusters/db-new/databases')
        && $request->data() === ['name' => 'production']);

    Http::assertSent(fn (Request $request) => isAttach($request, 'schema-new'));

    // Cluster, then database, then attach.
    $writes = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => isWrite($request) && ! str_ends_with($request->url(), '/environments/env-1') || isAttach($request))
        ->map(fn (Request $request) => $request->method().' '.parse_url($request->url(), PHP_URL_PATH))
        ->values()
        ->all();

    expect($writes)->toBe([
        'POST /api/databases/clusters',
        'POST /api/databases/clusters/db-new/databases',
        'PATCH /api/environments/env-1',
    ]);

    $state = json_decode(file_get_contents($this->statePath), true);

    expect($state['databases']['kickback'])->toBe([
        'id' => 'db-new',
        'schemas' => ['production' => ['id' => 'schema-new']],
    ]);
});

test('neon clusters keep the default database the API insists on', function () {
    config(['cloud.databases.kickback.type' => 'neon_serverless_postgres']);

    fakeCloudApi($this->baseRoutes + [
        'POST /databases/clusters' => Http::response(['data' => ['id' => 'db-new']], 201),
        'GET /databases/clusters/db-new' => ['data' => ['id' => 'db-new', 'attributes' => ['status' => 'available']]],
        'POST /databases/clusters/db-new/databases' => Http::response(['data' => ['id' => 'schema-new']], 201),
        'GET /environments/env-1' => environmentWithDatabase(null),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases/clusters')
        && ! array_key_exists('create_default_database', $request->data()));
});

test('an environment can name its own database', function () {
    config(['cloud.databases.kickback.environments' => ['production' => 'kickback_live']]);

    fakeCloudApi($this->baseRoutes + [
        'GET /databases/clusters' => ['data' => [['id' => 'db-1', 'attributes' => ['name' => 'kickback']]]],
        'GET /databases/clusters/db-1' => ['data' => ['id' => 'db-1', 'attributes' => ['status' => 'available']]],
        'GET /databases/clusters/db-1/databases' => ['data' => [['id' => 'schema-1', 'attributes' => ['name' => 'production']]]],
        'POST /databases/clusters/db-1/databases' => Http::response(['data' => ['id' => 'schema-live']], 201),
        'GET /environments/env-1' => environmentWithDatabase(null),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases/clusters/db-1/databases')
        && $request['name'] === 'kickback_live');

    Http::assertSent(fn (Request $request) => isAttach($request, 'schema-live'));
});

test('nothing is changed when the database is already attached', function () {
    file_put_contents($this->statePath, json_encode([
        'application_id' => 'app-1',
        'environments' => ['production' => ['id' => 'env-1']],
        'databases' => ['kickback' => ['id' => 'db-1', 'schemas' => ['production' => ['id' => 'schema-1']]]],
    ], JSON_PRETTY_PRINT));
    touch($this->statePath, $mtime = time() - 3600);
    $before = file_get_contents($this->statePath);

    fakeCloudApi($this->baseRoutes + [
        'GET /environments/env-1' => environmentWithDatabase('schema-1'),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->expectsOutputToContain('Database kickback/production is already attached.')
        ->expectsOutputToContain('.laravel-cloud-test.json is unchanged.')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => isAttach($request));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/databases/'));

    clearstatcache();
    expect(file_get_contents($this->statePath))->toBe($before)
        ->and(filemtime($this->statePath))->toBe($mtime);
});

test('a different database that is already attached is never detached or swapped', function () {
    fakeCloudApi($this->baseRoutes + [
        'GET /databases/clusters' => ['data' => [['id' => 'db-1', 'attributes' => ['name' => 'kickback']]]],
        'GET /databases/clusters/db-1/databases' => ['data' => [['id' => 'schema-1', 'attributes' => ['name' => 'production']]]],
        'GET /environments/env-1' => environmentWithDatabase('schema-hand-picked'),
    ]);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])
        ->expectsOutputToContain('Another database (schema-hand-picked) is attached to this environment')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => isAttach($request));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/databases'));
});

test('dry run prints the database plan and creates nothing', function () {
    fakeCloudApi($this->baseRoutes + [
        'GET /databases/clusters' => ['data' => []],
    ]);

    $this->artisan('cloud:deploy', ['--dry-run' => true])
        ->expectsOutputToContain('[DRY RUN] Would create database cluster kickback with: {"name":"kickback","type":"laravel_mysql"')
        ->expectsOutputToContain('[DRY RUN] Would create database production in kickback')
        ->expectsOutputToContain('[DRY RUN] Would attach database kickback/production')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => isWrite($request));

    expect(file_exists($this->statePath))->toBeFalse();
});

test('dry run shows an attach for an existing database that is not attached yet', function () {
    fakeCloudApi($this->baseRoutes + [
        'GET /databases/clusters' => ['data' => [['id' => 'db-1', 'attributes' => ['name' => 'kickback']]]],
        'GET /databases/clusters/db-1/databases' => ['data' => [['id' => 'schema-1', 'attributes' => ['name' => 'production']]]],
        'GET /environments/env-1' => environmentWithDatabase(null),
    ]);

    $this->artisan('cloud:deploy', ['--dry-run' => true])
        ->expectsOutputToContain('[DRY RUN] Would attach database kickback/production (schema-1)')
        ->expectsOutputToContain('[DRY RUN] .laravel-cloud-test.json would be updated with the IDs found above. It was not written.')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => isWrite($request));

    expect(file_exists($this->statePath))->toBeFalse();
});

test('an environment listed under two clusters is rejected before anything is sent', function () {
    config(['cloud.databases.other' => ['type' => 'laravel_mysql', 'environments' => ['production']]]);

    Http::fake();

    $this->artisan('cloud:deploy', ['--dry-run' => true])
        ->expectsOutputToContain("Environment 'production' is listed under more than one database cluster.")
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('environments without a database are left alone', function () {
    config(['cloud.databases' => []]);

    fakeCloudApi($this->baseRoutes);

    $this->artisan('cloud:deploy', ['--skip-deploy' => true, '--force' => true])->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'databases')
        || str_contains($request->url(), 'include=database')
        || isAttach($request));
});
