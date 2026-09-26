<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\Commands\CloudMigrateFromForgeCommand;
use NativePhp\LaravelCloudDeploy\ForgeClient;
use NativePhp\LaravelCloudDeploy\Tests\Support\FakeApis;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/cloud-migrate-'.bin2hex(random_bytes(4));
    mkdir($this->project, 0777, true);

    CloudMigrateFromForgeCommand::$projectPath = $this->project;
    config(['cloud.state_path' => $this->project.'/.laravel-cloud.json']);
    config(['cloud.token' => null, 'cloud.forge' => null]);

    Sleep::fake();
    Process::fake(['command -v *' => Process::result('/usr/bin/tool')]);
});

afterEach(function () {
    CloudMigrateFromForgeCommand::$projectPath = null;
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->project);
});

test('setup explains the Forge token, retries a bad one and picks the organization', function () {
    FakeApis::fake([
        'GET /orgs' => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer bad')
            ? Http::response(['message' => 'Unauthenticated.'], 401)
            : FakeApis::page([
                FakeApis::resource('organizations', '1', ['name' => 'Acme', 'slug' => 'acme']),
                FakeApis::resource('organizations', '2', ['name' => 'Side Project', 'slug' => 'side']),
            ]),
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'setup'])
        ->expectsOutputToContain(ForgeClient::TOKEN_URL)
        ->expectsQuestion('Paste your Forge API token', 'bad')
        ->expectsOutputToContain('Forge rejected that token')
        ->expectsQuestion('Paste your Forge API token', 'forge-token')
        ->expectsQuestion('Which Forge organization is the site in?', 'acme')
        ->expectsConfirmation('Save FORGE_API_TOKEN and FORGE_ORGANIZATION to this project\'s .env so you don\'t have to paste it again?', 'no')
        ->expectsQuestion('Paste your Laravel Cloud API token', 'cloud-token')
        ->expectsConfirmation('The token belongs to the Cloud organization "Acme Cloud". Is that where the site should go?', 'yes')
        ->expectsConfirmation('Save LARAVEL_CLOUD_TOKEN to this project\'s .env so you don\'t have to paste it again?', 'no')
        ->expectsConfirmation('Is your Git provider connected to Laravel Cloud?', 'yes')
        ->assertExitCode(0);

    $state = migrationState($this->project);

    expect($state->get('migration.forge.organization'))->toBe('acme')
        ->and($state->get('migration.cloud.organization_id'))->toBe('org-1')
        ->and($state->get('migration.source_control.connected'))->toBe('confirmed')
        ->and($state->get('migration.steps.setup'))->not->toBeNull()
        ->and(file_get_contents($this->project.'/.laravel-cloud.json'))->not->toContain('forge-token');
});

test('setup can save the tokens to the project .env', function () {
    file_put_contents($this->project.'/.env', "APP_NAME=Shop\n");
    FakeApis::fake(cloud: ['GET /applications' => ['data' => [['id' => 'app-9', 'attributes' => ['repository' => ['full_name' => 'acme/other']]]]]]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'setup'])
        ->expectsQuestion('Paste your Forge API token', 'forge-token')
        ->expectsConfirmation('Save FORGE_API_TOKEN and FORGE_ORGANIZATION to this project\'s .env so you don\'t have to paste it again?', 'yes')
        ->expectsQuestion('Paste your Laravel Cloud API token', 'cloud-token')
        ->expectsConfirmation('The token belongs to the Cloud organization "Acme Cloud". Is that where the site should go?', 'yes')
        ->expectsConfirmation('Save LARAVEL_CLOUD_TOKEN to this project\'s .env so you don\'t have to paste it again?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($this->project.'/.env'))
        ->toContain('FORGE_API_TOKEN=forge-token')
        ->toContain('FORGE_ORGANIZATION=acme')
        ->toContain('LARAVEL_CLOUD_TOKEN=cloud-token');

    foreach (['FORGE_API_TOKEN', 'FORGE_ORGANIZATION', 'LARAVEL_CLOUD_TOKEN'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

test('an organization that already has applications is treated as having GitHub connected', function () {
    config(['cloud.token' => 'cloud-token', 'cloud.forge' => ['token' => 'forge-token']]);

    FakeApis::fake(cloud: ['GET /applications' => ['data' => [['id' => 'app-9', 'attributes' => []]]]]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'setup'])
        ->expectsConfirmation('The token belongs to the Cloud organization "Acme Cloud". Is that where the site should go?', 'yes')
        ->expectsOutputToContain('already has 1 application(s)')
        ->doesntExpectOutputToContain('Is your Git provider connected')
        ->assertExitCode(0);

    expect(migrationState($this->project)->get('migration.source_control.connected'))->toBe('assumed');
});

test('a rejected Cloud token is asked for again', function () {
    config(['cloud.forge' => ['token' => 'forge-token']]);

    FakeApis::fake(cloud: [
        'GET /meta/organization' => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer expired')
            ? Http::response(['message' => 'Unauthenticated.'], 401)
            : ['data' => ['id' => 'org-1', 'attributes' => ['name' => 'Acme Cloud', 'slug' => 'acme']]],
        'GET /applications' => ['data' => [['id' => 'app-9']]],
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'setup'])
        ->expectsQuestion('Paste your Laravel Cloud API token', 'expired')
        ->expectsOutputToContain('Cloud rejected that token')
        ->expectsQuestion('Paste your Laravel Cloud API token', 'cloud-token')
        ->expectsConfirmation('The token belongs to the Cloud organization "Acme Cloud". Is that where the site should go?', 'yes')
        ->expectsConfirmation('Save LARAVEL_CLOUD_TOKEN to this project\'s .env so you don\'t have to paste it again?', 'no')
        ->assertExitCode(0);
});

test('without a connected provider the user is walked through connecting one', function () {
    config(['cloud.token' => 'cloud-token', 'cloud.forge' => ['token' => 'forge-token']]);
    FakeApis::fake();

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'setup'])
        ->expectsConfirmation('The token belongs to the Cloud organization "Acme Cloud". Is that where the site should go?', 'yes')
        ->expectsOutputToContain('Source control')
        ->expectsConfirmation('Is your Git provider connected to Laravel Cloud?', 'no')
        ->expectsConfirmation('Keep waiting while you connect it?', 'no')
        ->expectsOutputToContain('Connect your Git provider to Laravel Cloud, then run this command again.')
        ->assertExitCode(1);
});

test('a dry run inspects the site and prints the config without creating anything', function () {
    config(['cloud.token' => 'cloud-token', 'cloud.forge' => ['token' => 'forge-token', 'organization' => 'acme']]);
    FakeApis::fake(cloud: ['GET /applications' => ['data' => [['id' => 'app-9', 'attributes' => []]]]]);

    $this->artisan('cloud:migrate-from-forge', ['--dry-run' => true])
        ->expectsConfirmation('The token belongs to the Cloud organization "Acme Cloud". Is that where the site should go?', 'yes')
        ->expectsQuestion('Which server is the site on?', '101')
        ->expectsQuestion('Which site do you want to move to Laravel Cloud?', '202')
        ->expectsOutputToContain('Migrates')
        ->expectsOutputToContain('24 hours before you cut over')
        ->expectsQuestion('Which Cloud region should the app run in?', 'eu-west-2')
        ->expectsQuestion('What should the application be called in Cloud?', 'Shop')
        ->expectsQuestion('What should the Cloud environment be called?', 'production')
        ->expectsConfirmation('Create a public bucket for storage/app/public?', 'yes')
        ->expectsConfirmation('Create a private bucket for other files in storage/app?', 'no')
        ->expectsOutputToContain("'repository' => 'acme/shop'")
        ->expectsOutputToContain('Dry run finished')
        ->assertExitCode(0);

    expect(file_exists($this->project.'/.laravel-cloud.json'))->toBeFalse()
        ->and(file_exists($this->project.'/config/cloud.php'))->toBeFalse();

    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

test('a site with blockers stops the migration with the reasons', function () {
    prepareMigration($this->project, ['setup', 'site', 'inspect']);

    FakeApis::fake();
    $state = migrationState($this->project);
    $state->set('migration.inspection.site.app_type', 'WordPress');
    $state->save();

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'report'])
        ->expectsOutputToContain('BLOCKED')
        ->expectsOutputToContain('This site can\'t be migrated automatically.')
        ->assertExitCode(1);
});

test('a stopped run resumes at the next step', function () {
    prepareMigration($this->project, ['setup', 'site', 'inspect', 'report']);
    unlink($this->project.'/config/cloud.php');
    FakeApis::fake();

    $this->artisan('cloud:migrate-from-forge')
        ->expectsQuestion('You\'ve done 4 of 11 steps. Next up: Write config/cloud.php.', 'resume')
        ->expectsQuestion('Which Cloud region should the app run in?', 'eu-west-2')
        ->expectsQuestion('What should the application be called in Cloud?', 'Shop')
        ->expectsQuestion('What should the Cloud environment be called?', 'production')
        ->expectsConfirmation('Create a public bucket for storage/app/public?', 'no')
        ->expectsConfirmation('Create a private bucket for other files in storage/app?', 'no')
        ->expectsConfirmation('Continue to creating the resources in Cloud?', 'no')
        ->expectsOutputToContain('Stopped here.')
        ->assertExitCode(0);

    $state = migrationState($this->project);

    expect($state->get('migration.steps.config'))->not->toBeNull()
        ->and($state->get('migration.steps.provision'))->toBeNull()
        ->and(file_get_contents($this->project.'/config/cloud.php'))->toContain("'shop-db' => [");
});

test('quitting at the resume prompt changes nothing', function () {
    prepareMigration($this->project, ['setup', 'site']);
    $requestsBefore = count(Http::recorded());

    $this->artisan('cloud:migrate-from-forge')
        ->expectsQuestion('You\'ve done 2 of 11 steps. Next up: Read the site from Forge.', 'quit')
        ->assertExitCode(0);

    expect(Http::recorded())->toHaveCount($requestsBefore);
});

test('an unknown step name is rejected', function () {
    $this->artisan('cloud:migrate-from-forge', ['--step' => 'teleport'])
        ->expectsOutputToContain('There is no step called "teleport"')
        ->assertExitCode(1);
});

test('the env step copies variables without the ones Cloud injects', function () {
    prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config', 'provision']);

    $state = migrationState($this->project);
    $state->setEnvironmentId('production', 'env-1');
    $state->save();

    FakeApis::fake(cloud: [
        'GET /environments/env-1' => ['data' => ['id' => 'env-1', 'attributes' => ['vanity_domain' => 'shop-main-abc.laravel.cloud']]],
        'POST /environments/env-1/variables' => ['data' => []],
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'env'])
        ->expectsOutputToContain('POSTMARK_TOKEN')
        ->doesntExpectOutputToContain('pm-secret')
        ->doesntExpectOutputToContain('forge-db-secret')
        ->expectsConfirmation('Copy 12 variables to the Cloud environment?', 'yes')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/environments/env-1/variables')) {
            return false;
        }

        $variables = collect($request['variables'])->pluck('value', 'key');

        return $request['method'] === 'set'
            && $variables['APP_URL'] === 'https://shop-main-abc.laravel.cloud'
            && $variables['POSTMARK_TOKEN'] === 'pm-secret'
            && ! $variables->has('DB_PASSWORD')
            && ! $variables->has('DB_HOST');
    });
});

test('provisioning explains the Git provider again when the application cannot be created', function () {
    prepareMigration($this->project, ['setup', 'site', 'inspect', 'report', 'config']);

    FakeApis::fake(cloud: [
        'POST /applications' => Http::response([
            'message' => 'The repository could not be found.',
            'errors' => ['repository' => ['The repository could not be found.']],
        ], 422),
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'provision'])
        ->expectsConfirmation('Create these resources in Laravel Cloud now? (They are billed by Cloud.)', 'yes')
        ->expectsOutputToContain('Cloud couldn\'t create the application')
        ->expectsOutputToContain('laravel-cloud-app')
        ->expectsConfirmation('Try creating the application again?', 'no')
        ->assertExitCode(0);

    expect(migrationState($this->project)->get('migration.steps.provision'))->toBeNull();
});

test('a site whose repository already has a Cloud app is added to it as a new environment', function () {
    prepareMigration($this->project, ['setup', 'site', 'inspect', 'report']);

    // The existing config for the production environment of the same app.
    file_put_contents($this->project.'/config/cloud.php', <<<'PHP'
<?php

return [
    'token' => env('LARAVEL_CLOUD_TOKEN'),
    'application' => ['name' => 'Shop', 'repository' => 'acme/shop', 'source_control' => 'github', 'region' => 'eu-west-2'],
    'environments' => [
        'production' => ['branch' => 'main', 'php' => '8.4:1'],
    ],
    'variables' => ['global' => ['APP_NAME' => env('APP_NAME', 'Shop')], 'production' => []],
    'databases' => ['shop-db' => ['type' => 'laravel_mysql', 'environments' => ['production' => 'shop']]],
];
PHP);

    $state = migrationState($this->project);
    $state->set('migration.inspection.site.repository.branch', 'develop');
    $state->save();

    FakeApis::fake(cloud: [
        'GET /applications' => ['data' => [[
            'id' => 'app-1', 'type' => 'applications',
            'attributes' => ['name' => 'Shop', 'region' => 'eu-west-2', 'repository' => ['full_name' => 'acme/shop', 'default_branch' => 'main']],
        ]]],
        'GET /applications/app-1/environments' => ['data' => [['id' => 'env-prod', 'attributes' => ['name' => 'production']]]],
    ]);

    putenv('LARAVEL_CLOUD_TOKEN=secret-token-value');

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'config'])
        ->expectsConfirmation('Add this site to "Shop" as a new environment?', 'yes')
        ->expectsQuestion('What should the Cloud environment be called?', 'develop')
        ->expectsConfirmation('Create a public bucket for storage/app/public?', 'no')
        ->expectsConfirmation('Create a private bucket for other files in storage/app?', 'no')
        ->expectsConfirmation('Add the "develop" environment to config/cloud.php? Other environments stay as they are, but comments in the file aren\'t kept.', 'yes')
        ->expectsConfirmation('Continue to creating the resources in Cloud?', 'no')
        ->assertExitCode(0);

    putenv('LARAVEL_CLOUD_TOKEN');

    $written = file_get_contents($this->project.'/config/cloud.php');
    $config = require $this->project.'/config/cloud.php';

    expect($written)->toContain("'token' => env('LARAVEL_CLOUD_TOKEN'),")
        ->toContain("env('APP_NAME', 'Shop')")
        ->not->toContain('secret-token-value')
        ->and(array_keys($config['environments']))->toBe(['production', 'develop'])
        ->and($config['environments']['production'])->toBe(['branch' => 'main', 'php' => '8.4:1'])
        ->and($config['environments']['develop']['branch'])->toBe('develop')
        ->and(array_keys($config['databases']))->toBe(['shop-db', 'shop-develop-db'])
        ->and($config['databases']['shop-develop-db']['environments'])->toBe(['develop' => 'shop'])
        ->and(migrationState($this->project)->get('migration.plan.environment'))->toBe('develop');
});

test('adding to an existing app refuses an environment name it already has', function () {
    prepareMigration($this->project, ['setup', 'site', 'inspect', 'report']);

    FakeApis::fake(cloud: [
        'GET /applications' => ['data' => [['id' => 'app-1', 'attributes' => ['name' => 'Shop', 'region' => 'eu-west-2', 'repository' => ['full_name' => 'acme/shop']]]]],
        'GET /applications/app-1/environments' => ['data' => [['id' => 'env-prod', 'attributes' => ['name' => 'production']]]],
    ]);

    $this->artisan('cloud:migrate-from-forge', ['--step' => 'config'])
        ->expectsConfirmation('Add this site to "Shop" as a new environment?', 'yes')
        ->expectsQuestion('What should the Cloud environment be called?', 'production')
        ->expectsOutputToContain('The app already has a "production" environment.')
        ->assertFailed();
});
