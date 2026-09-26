<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use NativePhp\LaravelCloudDeploy\ForgeClient;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;
use NativePhp\LaravelCloudDeploy\Migration\Support\ForgeInspector;
use NativePhp\LaravelCloudDeploy\Migration\Support\MigrationReport;
use NativePhp\LaravelCloudDeploy\Migration\Support\QueuePlanner;
use NativePhp\LaravelCloudDeploy\Tests\Support\FakeApis;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/cloud-migrate-project-'.bin2hex(random_bytes(4));
    mkdir($this->project.'/app/Http/Controllers', 0777, true);
});

afterEach(function () {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->project);
});

/**
 * Inspect the fake Forge site and generate its config.
 *
 * @param  array<string, mixed>  $forge
 * @return array{inspection: array<string, mixed>, config: array<string, mixed>, report: array<int, array<string, string>>}
 */
function inspectAndGenerate(string $project, array $forge = [], array $plan = []): array
{
    FakeApis::fake($forge);

    $inspection = (new ForgeInspector(new ForgeClient('token', 'acme')))->inspect('101', '202', $project);

    return [
        'inspection' => $inspection,
        'config' => ConfigGenerator::generate($inspection, array_merge(['app_name' => 'Shop', 'region' => 'eu-west-2'], $plan)),
        'report' => MigrationReport::build($inspection),
    ];
}

test('a plain Laravel site becomes a single app instance with a MySQL database', function () {
    ['config' => $config, 'report' => $report, 'inspection' => $inspection] = inspectAndGenerate($this->project);

    $production = $config['environments']['production'];

    expect($config['application'])->toBe(['name' => 'Shop', 'repository' => 'acme/shop', 'source_control' => 'github', 'region' => 'eu-west-2'])
        ->and($production['php'])->toBe('8.3:1')
        ->and($production['branch'])->toBe('main')
        ->and($production['build_commands'])->toBe(['composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader'])
        ->and($production['deploy_commands'])->toBe(['php artisan migrate --force'])
        ->and($production['octane'])->toBeFalse()
        ->and($production['instances']['App']['scheduler'])->toBeFalse()
        ->and($production['instances']['App']['processes'])->toBe([])
        ->and($config['databases']['shop-db']['type'])->toBe('laravel_mysql')
        ->and($config['databases']['shop-db']['environments'])->toBe(['production' => 'shop'])
        ->and($config['caches'])->toBe([])
        ->and(MigrationReport::hasBlockers($report))->toBeFalse()
        ->and($inspection['env']['facts'])->not->toHaveKey('DB_PASSWORD')
        ->and(json_encode($inspection))->not->toContain('forge-db-secret');
});

test('horizon, the scheduler and octane are carried over', function () {
    ['config' => $config] = inspectAndGenerate($this->project, [
        'GET /servers/101/sites/202/integrations/horizon' => FakeApis::integration('horizonIntegrations', ['enabled' => 'true', 'horizon_installed' => true]),
        'GET /servers/101/sites/202/integrations/octane' => FakeApis::integration('octaneIntegrations', ['enabled' => 'true', 'octane_installed' => true, 'port' => 8000]),
        'GET /servers/101/sites/202/integrations/laravel-scheduler' => FakeApis::integration('laravelSchedulerIntegrations', ['enabled' => true, 'laravel_installed' => true]),
        'GET /servers/101/background-processes' => FakeApis::page([
            FakeApis::process('1', 'php8.3 /home/forge/example.com/artisan horizon'),
            FakeApis::process('2', 'php8.3 /home/forge/example.com/artisan octane:start --no-interaction --port=8000'),
        ]),
        'GET /servers/101/sites/202/environment' => ['data' => FakeApis::resource('environments', '202', ['content' => FakeApis::env([
            'CACHE_STORE' => 'redis', 'QUEUE_CONNECTION' => 'redis',
        ])])],
    ]);

    $app = $config['environments']['production']['instances']['App'];

    expect($config['environments']['production']['octane'])->toBeTrue()
        ->and($app['scheduler'])->toBeTrue()
        ->and($app['processes'])->toBe(['horizon' => ['type' => 'custom', 'processes' => 1, 'command' => 'php artisan horizon']])
        ->and($config['caches']['shop-cache'])->toMatchArray(['type' => 'laravel_valkey', 'environments' => ['production']]);
});

test('a Postgres site gets a serverless Postgres cluster', function () {
    ['config' => $config, 'report' => $report] = inspectAndGenerate($this->project, [
        'GET /servers/101' => ['data' => FakeApis::server(['database_type' => 'postgres16'])],
        'GET /servers/101/sites/202/environment' => ['data' => FakeApis::resource('environments', '202', ['content' => FakeApis::env([
            'DB_CONNECTION' => 'pgsql', 'DB_PORT' => '5432', 'DB_DATABASE' => 'shop-prod',
        ])])],
    ]);

    expect($config['databases']['shop-db']['type'])->toBe('neon_serverless_postgres')
        ->and($config['databases']['shop-db']['config'])->toMatchArray(['cu_min' => 0.25, 'suspend_seconds' => 0])
        // "shop-prod" isn't a valid database name in Cloud, so a safe default is used.
        ->and($config['databases']['shop-db']['environments'])->toBe(['production' => 'laravel'])
        ->and(collect($report)->firstWhere('area', 'Database')['detail'])->toContain('Serverless Postgres');
});

test('multiple queue workers become separate worker processes', function () {
    ['config' => $config] = inspectAndGenerate($this->project, [
        'GET /servers/101/background-processes' => function (Request $request) {
            // Workers made as server daemons don't carry the site ID, so
            // they're only found by their path in the unfiltered list.
            $all = [
                FakeApis::process('1', 'php8.3 /home/forge/example.com/artisan queue:work redis --queue=high,default --tries=3 --timeout=120', 2),
                FakeApis::process('2', 'php8.3 /home/forge/example.com/artisan queue:work redis --queue=emails --sleep=10'),
                FakeApis::process('3', 'php8.3 /home/forge/other.com/artisan queue:work'),
            ];

            return str_contains(urldecode($request->url()), 'filter[site_id]') ? FakeApis::page([$all[0]]) : FakeApis::page($all);
        },
    ]);

    expect($config['environments']['production']['instances']['App']['processes'])->toBe([
        'worker-high-default' => [
            'type' => 'worker',
            'processes' => 2,
            'queue' => ['connection' => 'redis', 'queues' => ['high', 'default'], 'tries' => 3, 'backoff' => 0, 'timeout' => 120, 'sleep' => 3, 'rest' => 0, 'force' => false],
        ],
        'worker-emails' => [
            'type' => 'worker',
            'processes' => 1,
            'queue' => ['connection' => 'redis', 'queues' => ['emails'], 'tries' => 1, 'backoff' => 0, 'timeout' => 60, 'sleep' => 10, 'rest' => 0, 'force' => false],
        ],
    ]);
});

test('artisan daemons become custom processes and other daemons need manual work', function () {
    ['config' => $config, 'report' => $report] = inspectAndGenerate($this->project, [
        'GET /servers/101/background-processes' => FakeApis::page([
            FakeApis::process('1', 'php8.3 /home/forge/example.com/artisan pulse:check'),
            FakeApis::process('2', 'node /home/forge/example.com/ssr.js'),
        ]),
        'GET /servers/101/sites/202/scheduled-jobs' => FakeApis::page([
            FakeApis::job('1', 'php8.3 /home/forge/example.com/artisan schedule:run'),
            FakeApis::job('2', '/usr/bin/backup.sh', '0 3 * * *'),
        ]),
    ]);

    expect($config['environments']['production']['instances']['App']['processes'])->toBe([
        'pulse-check' => ['type' => 'custom', 'processes' => 1, 'command' => 'php artisan pulse:check'],
    ])
        ->and($config['environments']['production']['instances']['App']['scheduler'])->toBeTrue()
        ->and(collect($report)->where('status', MigrationReport::MANUAL)->pluck('detail')->implode("\n"))
        ->toContain('node /home/forge/example.com/ssr.js')
        ->toContain('/usr/bin/backup.sh');
});

test('GitLab and Bitbucket repositories map to Cloud source control providers', function (string $provider, string $url, string $cloud, string $name) {
    ['config' => $config, 'report' => $report] = inspectAndGenerate($this->project, [
        'GET /sites/202' => ['data' => FakeApis::site([
            'repository' => ['provider' => $provider, 'url' => $url, 'branch' => 'main', 'status' => 'installed'],
        ])],
    ]);

    expect($config['application']['source_control'])->toBe($cloud)
        ->and($config['application']['repository'])->toBe($name)
        ->and(MigrationReport::hasBlockers($report))->toBeFalse();
})->with([
    ['gitlab', 'git@gitlab.com:acme/web/shop.git', 'gitlab', 'acme/web/shop'],
    ['bitbucket', 'git@bitbucket.org:acme/shop.git', 'bitbucket', 'acme/shop'],
    ['gitlab-custom', 'ssh://git@git.acme.test:2222/acme/shop.git', 'gitlab_self_hosted', 'acme/shop'],
]);

test('unsupported sites are blocked with a reason', function () {
    ['report' => $report] = inspectAndGenerate($this->project, [
        'GET /sites/202' => ['data' => FakeApis::site([
            'app_type' => 'WordPress',
            'repository' => ['provider' => 'custom', 'url' => 'git@git.acme.test:acme/shop.git', 'branch' => 'main', 'status' => 'installed'],
        ])],
        'GET /servers/101/sites/202/environment' => ['data' => FakeApis::resource('environments', '202', ['content' => FakeApis::env(['DB_CONNECTION' => 'sqlite'])])],
    ]);

    $blockers = collect($report)->where('status', MigrationReport::BLOCKER)->pluck('detail')->implode("\n");

    expect(MigrationReport::hasBlockers($report))->toBeTrue()
        ->and($blockers)->toContain('WordPress')
        ->toContain('custom Git remote')
        ->toContain('SQLite');
});

test('local disk usage, redirects, basic auth and custom nginx rules are flagged', function () {
    file_put_contents($this->project.'/app/Http/Controllers/AvatarController.php', "<?php\n\$request->file('avatar')->store('avatars', 'public');\n");

    ['report' => $report, 'config' => $config] = inspectAndGenerate($this->project, [
        'GET /servers/101/sites/202/redirect-rules' => FakeApis::page([
            FakeApis::resource('redirect-rules', '1', ['from' => '/old', 'to' => '/new', 'type' => 'permanent', 'status' => 'installed', 'created_at' => '', 'updated_at' => '']),
        ]),
        'GET /servers/101/sites/202/security-rules' => FakeApis::page([
            FakeApis::resource('securityRules', '1', ['name' => 'Staff', 'path' => '/admin', 'status' => 'installed', 'created_at' => '', 'updated_at' => '']),
        ]),
        'GET /servers/101/sites/202/nginx' => ['data' => FakeApis::resource('nginxConfigs', '202', ['content' => "location /legacy {\n    return 301 /;\n}\n"])],
    ], ['public_bucket' => true]);

    $manual = collect($report)->where('status', MigrationReport::MANUAL)->pluck('detail')->implode("\n");

    expect($manual)->toContain('AvatarController.php:2')
        ->toContain('/old -> /new')
        ->toContain('/admin')
        ->toContain('location /legacy')
        ->and($config['buckets']['shop-public'])->toMatchArray(['visibility' => 'public', 'disk' => 'public', 'default' => false]);
});

test('Forge regions map to the nearest Cloud region and PHP versions are clamped', function () {
    expect(ConfigGenerator::suggestRegion('lon1'))->toBe('eu-west-2')
        ->and(ConfigGenerator::suggestRegion('fra1'))->toBe('eu-central-1')
        ->and(ConfigGenerator::suggestRegion('us-east-1'))->toBe('us-east-1')
        ->and(ConfigGenerator::suggestRegion('mars1'))->toBe('us-east-2')
        ->and(ConfigGenerator::phpVersion('php81'))->toBe('8.2:1')
        ->and(ConfigGenerator::phpVersion('php84'))->toBe('8.4:1')
        ->and(ConfigGenerator::phpVersion(null))->toBe('8.4:1');
});

/**
 * Forge routes for a site whose default queue connection is redis, with the given workers.
 *
 * @param  array<int, array<string, mixed>>  $processes
 * @return array<string, mixed>
 */
function redisQueueSite(array $processes): array
{
    return [
        'GET /servers/101/background-processes' => FakeApis::page($processes),
        'GET /servers/101/sites/202/environment' => ['data' => FakeApis::resource('environments', '202', ['content' => FakeApis::env([
            'QUEUE_CONNECTION' => 'redis',
        ])])],
    ];
}

function writeComposerLock(string $project, string $laravel, bool $awsSdk = true): void
{
    $packages = [['name' => 'laravel/framework', 'version' => "v{$laravel}"]];

    if ($awsSdk) {
        $packages[] = ['name' => 'aws/aws-sdk-php', 'version' => '3.300.0'];
    }

    file_put_contents($project.'/composer.lock', json_encode(['packages' => $packages]));
}

test('queue workers on the default connection become managed queues', function () {
    writeComposerLock($this->project, '12.70.1');

    ['config' => $config, 'report' => $report] = inspectAndGenerate($this->project, redisQueueSite([
        FakeApis::process('1', 'php8.3 /home/forge/example.com/artisan queue:work redis --queue=emails,default --tries=3', 3),
        FakeApis::process('2', 'php8.3 /home/forge/example.com/artisan queue:work --queue=reports --timeout=300'),
    ]));

    $instances = $config['environments']['production']['instances'];

    expect(array_keys($instances))->toBe(['App', 'default', 'emails', 'reports'])
        ->and($instances['App']['processes'])->toBe([])
        ->and($instances['default'])->toBe(['type' => 'managed_queue', 'size' => 'mq.flex.256mb', 'scaling' => ['type' => 'custom', 'min_replicas' => 0, 'max_replicas' => 3]])
        // A 300 second timeout is past Flex's 90 second limit.
        ->and($instances['reports']['size'])->toBe('mq.pro.256mb');

    $manual = collect($report)->where('status', MigrationReport::MANUAL)->pluck('detail')->implode("\n");

    expect($manual)->toContain('$tries and $backoff')
        ->toContain('Starter plan allows one managed queue')
        ->toContain('Growth plan')
        ->not->toContain('aws/aws-sdk-php');
});

test('workers stay worker processes when managed queues cannot take their jobs', function (array $processes, ?string $laravel, string $reason) {
    if ($laravel) {
        writeComposerLock($this->project, $laravel);
    }

    ['config' => $config, 'report' => $report] = inspectAndGenerate($this->project, redisQueueSite($processes));

    $instances = $config['environments']['production']['instances'];

    expect(collect($instances)->where('type', 'managed_queue'))->toBeEmpty()
        ->and(collect($instances['App']['processes'])->where('type', 'worker'))->not->toBeEmpty()
        ->and(collect($report)->pluck('detail')->implode("\n"))->toContain($reason);
})->with([
    'horizon' => [[
        FakeApis::process('1', 'php8.3 /home/forge/example.com/artisan horizon'),
        FakeApis::process('2', 'php8.3 /home/forge/example.com/artisan queue:work redis'),
    ], '12.70.0', 'uses Horizon'],
    'old laravel' => [[FakeApis::process('1', 'php artisan queue:work redis')], '11.20.0', 'runs Laravel 11.20.0'],
    'other connection' => [[FakeApis::process('1', 'php artisan queue:work sqs --queue=default')], '12.70.0', 'reads that connection explicitly'],
    'short queue name' => [[FakeApis::process('1', 'php artisan queue:work --queue=ai')], '12.70.0', '3 to 39 letters'],
]);

test('a missing aws sdk is flagged for managed queues', function () {
    writeComposerLock($this->project, '13.20.0', awsSdk: false);

    ['report' => $report] = inspectAndGenerate($this->project, redisQueueSite([
        FakeApis::process('1', 'php artisan queue:work redis'),
    ]));

    expect(collect($report)->pluck('detail')->implode("\n"))->toContain('composer require aws/aws-sdk-php');
});

test('laravel versions are checked against the managed queue minimums', function () {
    expect(QueuePlanner::laravelSupportsManagedQueues('11.55.0'))->toBeTrue()
        ->and(QueuePlanner::laravelSupportsManagedQueues('12.62.9'))->toBeFalse()
        ->and(QueuePlanner::laravelSupportsManagedQueues('v13.19.0'))->toBeTrue()
        ->and(QueuePlanner::laravelSupportsManagedQueues('10.48.0'))->toBeFalse()
        ->and(QueuePlanner::laravelSupportsManagedQueues('14.0.0'))->toBeTrue();
});
