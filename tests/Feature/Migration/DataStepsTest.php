<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\Commands\CloudMigrateFromForgeCommand;
use NativePhp\LaravelCloudDeploy\Tests\Support\FakeApis;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/cloud-migrate-'.bin2hex(random_bytes(4));
    mkdir($this->project, 0777, true);

    CloudMigrateFromForgeCommand::$projectPath = $this->project;
    config(['cloud.state_path' => $this->project.'/.laravel-cloud.json']);

    Sleep::fake();
});

afterEach(function () {
    CloudMigrateFromForgeCommand::$projectPath = null;
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->project);
});

/**
 * Fake the Forge server: ssh-keygen writes a key, and scripts sent over
 * ssh are answered by the first matching needle in $scripts.
 *
 * @param  array<string, mixed>  $scripts  needle => output string or Process::result()
 */
function fakeForgeServer(array $scripts = []): void
{
    $GLOBALS['forgeServerScripts'] = [];

    Process::fake(['*' => function (PendingProcess $process) use ($scripts) {
        $command = (array) $process->command;

        if (($command[0] ?? null) === 'ssh-keygen') {
            file_put_contents($command[array_search('-f', $command, true) + 1].'.pub', 'ssh-ed25519 AAAAtest laravel-cloud-migrate');

            return Process::result();
        }

        if (($command[0] ?? null) === 'ssh') {
            $input = (string) $process->input;
            $GLOBALS['forgeServerScripts'][] = $input;

            if (str_contains($input, 'echo connected')) {
                return Process::result('connected');
            }

            foreach ($scripts as $needle => $result) {
                if (str_contains($input, $needle)) {
                    return is_string($result) ? Process::result($result) : $result;
                }
            }
        }

        return Process::result('/usr/bin/tool');
    }]);
}

/**
 * Forge routes for adding, finding and removing the temporary SSH key.
 *
 * @return array<string, mixed>
 */
function sshKeyRoutes(): array
{
    $name = null;

    return [
        'POST /servers/101/ssh-keys' => function (Request $request) use (&$name) {
            $name = $request['name'];

            return Http::response(null, 202);
        },
        'GET /servers/101/ssh-keys' => function () use (&$name) {
            return FakeApis::page($name ? [FakeApis::resource('keys', '55', ['name' => $name, 'user' => 'forge', 'status' => 'installed', 'created_by' => 1, 'created_at' => '', 'updated_at' => ''])] : []);
        },
        'DELETE /servers/101/ssh-keys/55' => Http::response(null, 202),
    ];
}

/**
 * Cloud routes for a Laravel MySQL cluster whose public endpoint can be toggled.
 *
 * @return array<string, mixed>
 */
function mysqlClusterRoutes(bool $public = false): array
{
    return [
        'GET /databases/clusters/db-1' => function () use (&$public) {
            return ['data' => ['id' => 'db-1', 'type' => 'databases', 'attributes' => [
                'name' => 'shop-db', 'type' => 'laravel_mysql', 'status' => 'available', 'region' => 'eu-west-2',
                'config' => ['size' => 'mysql-flex-1gb', 'storage' => 10, 'is_public' => $public, 'uses_scheduled_snapshots' => true, 'retention_days' => 7],
                'connection' => ['hostname' => 'shop-db.mysql.laravel.cloud', 'port' => 3306, 'protocol' => 'mysql', 'driver' => 'mysql', 'username' => 'cloud_user', 'password' => 'cloud-db-secret'],
            ]]];
        },
        'PATCH /databases/clusters/db-1' => function (Request $request) use (&$public) {
            $public = $request['config']['is_public'];

            return ['data' => ['id' => 'db-1']];
        },
    ];
}

test('the database step copies server to server, verifies row counts and cleans up', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env']);
    $state->set('resources.databases.shop-db', ['id' => 'db-1', 'schemas' => ['shop' => 'schema-1']]);
    $state->save();

    FakeApis::fake(sshKeyRoutes(), mysqlClusterRoutes());
    fakeForgeServer([
        'missing:' => '',
        'mysqldump --single-transaction' => '',
        'count_tables' => "source\tusers\t3\nsource\torders\t7\ntarget\tusers\t3\ntarget\torders\t7\n",
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'database'])
        ->expectsConfirmation('Copy the database to Cloud now? The site keeps running on Forge while it copies.', 'yes')
        ->expectsOutputToContain('All 2 tables have the same number of rows')
        ->assertExitCode(0);

    // The key was added as the site user and removed afterwards.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/servers/101/ssh-keys')
        && $request['user'] === 'forge'
        && str_starts_with($request['key'], 'ssh-ed25519'));
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/ssh-keys/55'));

    // The public endpoint was switched on for the copy and off again, keeping the rest of the config.
    $patches = collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/databases/clusters/db-1'))
        ->values();

    expect($patches)->toHaveCount(2)
        ->and($patches[0]['config'])->toMatchArray(['is_public' => true, 'size' => 'mysql-flex-1gb'])
        ->and($patches[1]['config']['is_public'])->toBeFalse();

    // Passwords only travel inside the script on stdin, never as arguments.
    Process::assertRan(function (PendingProcess $process) {
        return ($process->command[0] ?? null) === 'ssh'
            && str_contains((string) $process->input, 'mysqldump --single-transaction')
            && str_contains((string) $process->input, "DST_PASSWORD='cloud-db-secret'")
            && str_contains((string) $process->input, "SRC_PASSWORD='forge-db-secret'")
            && ! str_contains(implode(' ', $process->command), 'secret');
    });

    $state = migrationState($this->project);

    expect($state->get('migration.cleanup'))->toBeEmpty()
        ->and($state->get('migration.database.copied_at'))->not->toBeNull()
        ->and($state->get('migration.steps.database'))->not->toBeNull()
        ->and(file_get_contents($this->project.'/.laravel-cloud.json'))->not->toContain('secret');
});

test('the database step still cleans up when the copy fails', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env']);
    $state->set('resources.databases.shop-db', ['id' => 'db-1', 'schemas' => ['shop' => 'schema-1']]);
    $state->save();

    FakeApis::fake(sshKeyRoutes(), mysqlClusterRoutes());
    fakeForgeServer([
        'missing:' => '',
        'mysqldump --single-transaction' => Process::result('', 'ERROR 1045 (28000): Access denied', 1),
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'database'])
        ->expectsConfirmation('Copy the database to Cloud now? The site keeps running on Forge while it copies.', 'yes')
        ->expectsOutputToContain('Access denied')
        ->assertExitCode(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/ssh-keys/55'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && ($request->data()['config']['is_public'] ?? null) === false);

    expect(migrationState($this->project)->get('migration.cleanup'))->toBeEmpty()
        ->and(migrationState($this->project)->get('migration.steps.database'))->toBeNull();
});

test('missing database tools on the server are reported with an install hint', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env']);
    $state->set('resources.databases.shop-db', ['id' => 'db-1', 'schemas' => ['shop' => 'schema-1']]);
    $state->save();

    FakeApis::fake(sshKeyRoutes(), mysqlClusterRoutes());
    fakeForgeServer(['missing:' => "missing:mysqldump\n"]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'database'])
        ->expectsConfirmation('Copy the database to Cloud now? The site keeps running on Forge while it copies.', 'yes')
        ->expectsOutputToContain('The Forge server is missing mysqldump.')
        ->expectsOutputToContain('apt-get install -y mysql-client')
        ->assertExitCode(1);
});

test('a run that crashed mid-copy cleans up when the command starts again', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env']);
    $directory = sys_get_temp_dir().'/laravel-cloud-migrate-crashed';
    @mkdir($directory);
    $state->set('migration.cleanup', [
        'ssh_key' => ['server_id' => '101', 'name' => 'laravel-cloud-migrate-crashed', 'directory' => $directory],
        'database_public' => ['cluster_id' => 'db-1'],
    ]);
    $state->save();

    $routes = mysqlClusterRoutes(public: true);

    FakeApis::fake([
        'GET /servers/101/ssh-keys' => FakeApis::page([FakeApis::resource('keys', '55', ['name' => 'laravel-cloud-migrate-crashed', 'user' => 'forge', 'status' => 'installed'])]),
        'DELETE /servers/101/ssh-keys/55' => Http::response(null, 202),
    ], $routes);
    fakeForgeServer();

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'setup'])
        ->expectsOutputToContain('Removed the temporary SSH key')
        ->expectsOutputToContain('Turned public access to the Cloud database back off')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/ssh-keys/55'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && ($request->data()['config']['is_public'] ?? null) === false);

    expect(is_dir($directory))->toBeFalse()
        ->and(migrationState($this->project)->get('migration.cleanup'))->toBeEmpty();
});

test('the files step copies each storage directory to its bucket with rclone on the server', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env', 'database'], [
        'public_bucket' => true,
        'private_bucket' => true,
    ]);
    $state->set('resources.buckets', [
        'shop-public' => ['id' => 'bucket-pub', 'key_id' => 'key-pub'],
        'shop-private' => ['id' => 'bucket-priv', 'key_id' => 'key-priv'],
    ]);
    $state->save();

    $bucket = fn (string $id, string $name, string $visibility) => ['data' => ['id' => $id, 'type' => 'filesystems', 'attributes' => [
        'name' => $name, 'type' => 'cloudflare_r2', 'status' => 'available', 'visibility' => $visibility,
        'jurisdiction' => 'default', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'url' => null,
    ]]];
    $key = fn (string $id) => ['data' => ['id' => $id, 'type' => 'filesystemKeys', 'attributes' => [
        'name' => $id, 'permission' => 'read_write', 'access_key_id' => "AK-{$id}", 'access_key_secret' => "secret-{$id}",
    ]]];

    FakeApis::fake(sshKeyRoutes(), [
        'GET /buckets/bucket-pub' => $bucket('bucket-pub', 'shop-public', 'public'),
        'GET /buckets/bucket-priv' => $bucket('bucket-priv', 'shop-private', 'private'),
        'GET /bucket-keys/key-pub' => $key('key-pub'),
        'GET /bucket-keys/key-priv' => $key('key-priv'),
    ]);

    fakeForgeServer([
        'artisan" ]' => "app:/home/forge/example.com\n",
        "find -L 'storage/app/public'" => "dir\tstorage/app/public\t4\t4096\ndir\tstorage/app/private\t0\t0\ndir\tstorage/app\t2\t100\ntool:rclone\n",
        'rclone copy' => '',
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'files'])
        ->expectsConfirmation('Copy the stored files to Cloud now?', 'yes')
        ->expectsOutputToContain('Files copied.')
        ->assertExitCode(0);

    $rcloneRuns = collect($GLOBALS['forgeServerScripts'])
        ->filter(fn (string $input) => str_contains($input, 'rclone copy'))
        ->values();

    // storage/app/private was empty, so only two copies ran.
    expect($rcloneRuns)->toHaveCount(2)
        ->and($rcloneRuns[0])->toContain("rclone copy --copy-links --exclude .gitignore  'storage/app/public'")
        ->toContain("BUCKET='shop-public'")
        ->and($rcloneRuns[1])->toContain("--exclude '/public/**' --exclude '/private/**' 'storage/app'")
        ->toContain("BUCKET='shop-private'")
        ->toContain("SECRET='secret-key-priv'");

    expect(migrationState($this->project)->get('migration.files.app_path'))->toBe('/home/forge/example.com');
});

test('the deploy step deploys and checks the Cloud URL', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env', 'database', 'files']);
    $state->setEnvironmentId('production', 'env-1');
    $state->save();

    FakeApis::fake(cloud: [
        'POST /environments/env-1/deployments' => Http::response(['data' => ['id' => 'dep-1']], 201),
        'GET /deployments/dep-1' => ['data' => ['id' => 'dep-1', 'attributes' => ['status' => 'deployment.succeeded']]],
        'GET /environments/env-1' => ['data' => ['id' => 'env-1', 'attributes' => ['vanity_domain' => 'shop-main-abc.laravel.cloud']]],
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'deploy'])
        ->expectsConfirmation('Deploy to Cloud now?', 'yes')
        ->expectsOutputToContain('https://shop-main-abc.laravel.cloud answered with HTTP 200')
        ->assertExitCode(0);
});

test('a failed deploy shows the failing build step and exits non-zero', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env', 'database', 'files']);
    $state->setEnvironmentId('production', 'env-1');
    $state->save();

    FakeApis::fake(cloud: [
        'POST /environments/env-1/deployments' => Http::response(['data' => ['id' => 'dep-1']], 201),
        'GET /deployments/dep-1' => ['data' => ['id' => 'dep-1', 'attributes' => ['status' => 'build.failed', 'failure_reason' => 'Build failed']]],
        'GET /deployments/dep-1/logs' => ['data' => [
            'build' => ['available' => true, 'steps' => [
                ['step' => 'composer', 'status' => 'failed', 'description' => 'Installing Composer dependencies', 'output' => "Your requirements could not be resolved\next-imagick is missing"],
            ]],
            'deploy' => ['available' => false, 'steps' => []],
        ], 'meta' => ['deployment_status' => 'build.failed']],
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'deploy'])
        ->expectsConfirmation('Deploy to Cloud now?', 'yes')
        ->expectsOutputToContain('The deployment failed: Build failed')
        ->expectsOutputToContain('ext-imagick is missing')
        ->assertExitCode(1);
});

test('cutover puts Forge in maintenance, recopies data, adds domains and resumes while DNS propagates', function () {
    $state = prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision', 'env', 'database', 'files', 'deploy']);
    $state->setEnvironmentId('production', 'env-1');
    $state->set('resources.databases.shop-db', ['id' => 'db-1', 'schemas' => ['shop' => 'schema-1']]);
    $state->save();

    $verified = false;
    $domain = function () use (&$verified) {
        return ['data' => ['id' => 'dom-1', 'type' => 'domains', 'attributes' => [
            'name' => 'example.com', 'type' => 'root', 'stage' => 'origin',
            'hostname_status' => $verified ? 'verified' : 'pending',
            'ssl_status' => $verified ? 'verified' : 'pending',
            'origin_status' => $verified ? 'verified' : 'pending',
            'dns_records' => [
                'ssl' => [['type' => 'TXT', 'name' => '_acme-challenge.example.com', 'value' => 'token-123']],
                'pre_verification' => '', 'origin' => '203.0.113.99', 'origin_cname' => 'shop.laravel.cloud', 'dcv' => '',
            ],
        ]]];
    };

    FakeApis::fake(array_merge(sshKeyRoutes(), [
        'POST /servers/101/sites/202/integrations/laravel-maintenance' => Http::response(null, 202),
    ]), array_merge(mysqlClusterRoutes(), [
        'GET /environments/env-1/domains' => ['data' => []],
        'POST /environments/env-1/domains' => Http::response($domain(), 200),
        'POST /domains/dom-1/verify' => fn () => $domain(),
        'POST /environments/env-1/variables' => ['data' => []],
    ]));

    fakeForgeServer([
        'missing:' => '',
        'mysqldump --single-transaction' => '',
        'count_tables' => "source\tusers\t3\ntarget\tusers\t3\n",
    ]);

    $this->artisan('cloud:migrate-from-forge')
        ->expectsQuestion('You\'ve done 10 of 11 steps. Next up: Cut over.', 'resume')
        ->expectsOutputToContain('Lower the TTL')
        ->expectsConfirmation('Ready to put the Forge site into maintenance mode and start the cutover?', 'yes')
        ->expectsQuestion('Which domains should move to Cloud?', ['example.com'])
        ->expectsOutputToContain('_acme-challenge.example.com')
        ->expectsQuestion('DNS changes can take a while to spread. What now?', 'later')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/integrations/laravel-maintenance')
        && $request['status'] === 503);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/environments/env-1/domains')
        && $request['name'] === 'example.com'
        && $request['www_redirect'] === 'www_to_root');

    $state = migrationState($this->project);
    expect($state->get('migration.cutover'))->toMatchArray(['maintenance' => true, 'database' => true, 'files' => true, 'domains' => true])
        ->and($state->get('migration.steps.cutover'))->toBeNull();

    // Next run: DNS has propagated. Nothing is copied again.
    $verified = true;
    $requestsBefore = count(Http::recorded());

    $this->artisan('cloud:migrate-from-forge')
        ->expectsQuestion('You\'ve done 10 of 11 steps. Next up: Cut over.', 'resume')
        ->expectsOutputToContain('Every domain is verified')
        ->expectsOutputToContain('APP_URL is now https://example.com.')
        ->expectsConfirmation('Redeploy so the new APP_URL takes effect?', 'no')
        ->expectsOutputToContain('To roll back')
        ->assertExitCode(0);

    $later = collect(Http::recorded())->slice($requestsBefore)->map(fn ($pair) => $pair[0]);

    expect($later->contains(fn (Request $request) => str_contains($request->url(), 'ssh-keys') || str_contains($request->url(), 'maintenance')))->toBeFalse()
        ->and($later->contains(fn (Request $request) => str_ends_with($request->url(), '/environments/env-1/variables')
            && $request['variables'] === [['key' => 'APP_URL', 'value' => 'https://example.com']]))->toBeTrue()
        ->and(migrationState($this->project)->get('migration.steps.cutover'))->not->toBeNull();
});
