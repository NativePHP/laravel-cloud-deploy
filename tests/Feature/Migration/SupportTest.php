<?php

declare(strict_types=1);

use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigFile;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigRenderer;
use NativePhp\LaravelCloudDeploy\Migration\Support\DatabaseScripts;
use NativePhp\LaravelCloudDeploy\Migration\Support\DeployScriptParser;
use NativePhp\LaravelCloudDeploy\Migration\Support\EnvExpression;
use NativePhp\LaravelCloudDeploy\Migration\Support\EnvFile;
use NativePhp\LaravelCloudDeploy\Migration\Support\EnvironmentVariableFilter;
use NativePhp\LaravelCloudDeploy\Migration\Support\FileSyncScripts;
use NativePhp\LaravelCloudDeploy\Migration\Support\ForgeInspector;
use NativePhp\LaravelCloudDeploy\Migration\Support\ProcessClassifier;

test('env files parse quotes, comments, exports and multi-line values', function () {
    $env = EnvFile::parse(<<<'ENV'
# comment
APP_NAME="My App"
export APP_ENV=production
APP_KEY=base64:abc= # trailing comment
LITERAL='keep $this'
ESCAPED="line\nnext \"quoted\""
MULTI="first
second"
EMPTY=
URL=${APP_URL}/path
ENV);

    expect($env)->toBe([
        'APP_NAME' => 'My App',
        'APP_ENV' => 'production',
        'APP_KEY' => 'base64:abc=',
        'LITERAL' => 'keep $this',
        'ESCAPED' => "line\nnext \"quoted\"",
        'MULTI' => "first\nsecond",
        'EMPTY' => '',
        'URL' => '${APP_URL}/path',
    ]);
});

test('env files are updated in place and appended to', function () {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_NAME=Shop\nFORGE_API_TOKEN=old\n");

    EnvFile::put($path, ['FORGE_API_TOKEN' => 'new token', 'LARAVEL_CLOUD_TOKEN' => 'abc|123']);

    expect(file_get_contents($path))->toBe("APP_NAME=Shop\nFORGE_API_TOKEN=\"new token\"\nLARAVEL_CLOUD_TOKEN=abc|123\n")
        ->and(EnvFile::parse(file_get_contents($path))['FORGE_API_TOKEN'])->toBe('new token');

    unlink($path);
});

test('queue worker commands are parsed into Cloud worker settings', function (string $command, array $expected) {
    expect(ProcessClassifier::classify($command))->toBe(ProcessClassifier::WORKER)
        ->and(array_intersect_key(ProcessClassifier::parseWorker($command), $expected))->toBe($expected);
})->with([
    'forge worker' => [
        'php8.3 /home/forge/example.com/artisan queue:work redis --sleep=3 --quiet --timeout=90 --tries=3 --queue="high,default"',
        ['connection' => 'redis', 'queues' => ['high', 'default'], 'tries' => 3, 'timeout' => 90, 'sleep' => 3, 'force' => false],
    ],
    'bare worker' => [
        'php artisan queue:work',
        ['connection' => null, 'queues' => ['default'], 'tries' => null],
    ],
    'space separated options' => [
        'php /home/forge/example.com/current/artisan queue:listen database --queue emails --backoff 10,60 --force',
        ['connection' => 'database', 'queues' => ['emails'], 'backoff' => 10, 'force' => true],
    ],
]);

test('background processes are classified by command', function (string $command, string $kind) {
    expect(ProcessClassifier::classify($command))->toBe($kind);
})->with([
    ['php8.3 /home/forge/example.com/artisan horizon', ProcessClassifier::HORIZON],
    ['php /home/forge/example.com/artisan octane:start --server=swoole --port=8000', ProcessClassifier::OCTANE],
    ['php artisan reverb:start --port=8080', ProcessClassifier::REVERB],
    ['php8.3 artisan pulse:check', ProcessClassifier::ARTISAN],
    ['node /home/forge/example.com/server.js', ProcessClassifier::OTHER],
]);

test('forge deploy scripts become build and deploy commands', function () {
    $result = DeployScriptParser::parse(<<<'SH'
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
$FORGE_PHP artisan optimize
$FORGE_PHP artisan storage:link
if [ -f artisan ]; then $FORGE_PHP artisan migrate --force; fi
npm ci || npm install && npm run build
php8.3 artisan db:seed --class=RolesSeeder --force
curl -s https://hooks.example.com/deployed

$ACTIVATE_RELEASE()
$RESTART_QUEUES()
SH);

    expect($result['build'])->toBe([
        'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader',
        'php artisan optimize',
        'npm ci || npm install',
        'npm run build',
    ])
        ->and($result['deploy'])->toBe(['php artisan migrate --force', 'php artisan db:seed --class=RolesSeeder --force'])
        ->and($result['unmapped'])->toBe(['curl -s https://hooks.example.com/deployed'])
        ->and($result['dropped'][0])->toStartWith('php artisan storage:link');
});

test('deploy script lines with credentials are never copied', function () {
    $result = DeployScriptParser::parse(<<<'SH'
composer config http-basic.nova.laravel.com me@example.com s3cr3t-licence
curl -X POST https://hooks.slack.com/services/T000/B000/XXXX
curl https://user:pass@deploy.example.com/ping
$FORGE_COMPOSER install --no-dev
SH);

    expect(json_encode($result))->not->toContain('s3cr3t')
        ->not->toContain('XXXX')
        ->not->toContain('user:pass')
        ->and($result['build'])->toBe(['composer install --no-dev'])
        ->and($result['unmapped'])->toHaveCount(2); // the two curl lines read the same once redacted
});

test('a deploy script without composer install still installs dependencies', function () {
    expect(DeployScriptParser::parse("git pull\nphp artisan migrate --force")['build'])
        ->toBe(['composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader']);
});

test('environment variables injected by Cloud resources are dropped', function () {
    $result = EnvironmentVariableFilter::filter([
        'APP_NAME' => 'Shop',
        'APP_URL' => 'https://example.com',
        'DB_HOST' => '127.0.0.1',
        'DB_PASSWORD' => 'secret',
        'REDIS_HOST' => '127.0.0.1',
        'CACHE_STORE' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'FILESYSTEM_DISK' => 'local',
        'AWS_ACCESS_KEY_ID' => 'old',
        'NIGHTWATCH_TOKEN' => 'nw',
        'LOG_CHANNEL' => 'stack',
        'POSTMARK_TOKEN' => 'pm',
    ], ['database' => true, 'cache' => true, 'bucket' => true, 'app_url' => 'https://shop.laravel.cloud']);

    expect(array_keys($result['keep']))->toBe(['APP_NAME', 'APP_URL', 'QUEUE_CONNECTION', 'POSTMARK_TOKEN'])
        ->and($result['keep']['APP_URL'])->toBe('https://shop.laravel.cloud')
        ->and(array_keys($result['drop']))->toBe([
            'DB_HOST', 'DB_PASSWORD', 'REDIS_HOST', 'CACHE_STORE', 'FILESYSTEM_DISK',
            'AWS_ACCESS_KEY_ID', 'NIGHTWATCH_TOKEN', 'LOG_CHANNEL',
        ])
        ->and($result['replaced'])->toHaveKey('APP_URL')
        ->and($result['nightwatch_token'])->toBe('nw');
});

test('QUEUE_CONNECTION is dropped when managed queues are attached', function () {
    $result = EnvironmentVariableFilter::filter(['QUEUE_CONNECTION' => 'redis', 'APP_NAME' => 'Shop'], ['managed_queue' => true]);

    expect(array_keys($result['keep']))->toBe(['APP_NAME'])
        ->and($result['drop'])->toHaveKey('QUEUE_CONNECTION');
});

test('variables for resources that are not attached are kept', function () {
    $result = EnvironmentVariableFilter::filter([
        'DB_HOST' => 'db.example.com',
        'REDIS_HOST' => '127.0.0.1',
        'FILESYSTEM_DISK' => 's3',
        'AWS_ACCESS_KEY_ID' => 'key',
        'LOG_CHANNEL' => 'stack',
    ], ['database' => false, 'cache' => false, 'bucket' => true]);

    // The app already used S3, so its AWS credentials may be needed for SES/SQS too.
    expect(array_keys($result['keep']))->toBe(['DB_HOST', 'REDIS_HOST', 'AWS_ACCESS_KEY_ID', 'LOG_CHANNEL'])
        ->and(array_keys($result['drop']))->toBe(['FILESYSTEM_DISK']);
});

test('repository URLs are reduced to owner/repo', function (?string $url, ?string $expected) {
    expect(ForgeInspector::repositoryFullName($url))->toBe($expected);
})->with([
    ['git@github.com:acme/shop.git', 'acme/shop'],
    ['https://github.com/acme/shop', 'acme/shop'],
    ['acme/shop', 'acme/shop'],
    [null, null],
]);

test('custom nginx directives are picked out of the default config', function () {
    $directives = ForgeInspector::customNginxDirectives(<<<'NGINX'
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location /downloads {
    auth_basic "Restricted";
}
client_max_body_size 100M;
rewrite ^/old$ /new permanent;
NGINX);

    expect($directives)->toBe(['location /downloads {', 'auth_basic "Restricted";', 'client_max_body_size 100M;', 'rewrite ^/old$ /new permanent;']);
});

test('rendered config files load back to the same array', function () {
    $config = ['token' => new EnvExpression('LARAVEL_CLOUD_TOKEN'), 'list' => [1, 'two', 0.25], 'map' => ['on' => true, 'off' => null], 'empty' => []];

    $path = tempnam(sys_get_temp_dir(), 'cfg').'.php';
    file_put_contents($path, ConfigRenderer::render($config, 'Header'));
    putenv('LARAVEL_CLOUD_TOKEN=from-env');

    expect(require $path)->toBe(['token' => 'from-env', 'list' => [1, 'two', 0.25], 'map' => ['on' => true, 'off' => null], 'empty' => []])
        ->and(file_get_contents($path))->toContain("env('LARAVEL_CLOUD_TOKEN')");

    putenv('LARAVEL_CLOUD_TOKEN');
    unlink($path);
});

test('database scripts keep passwords out of command arguments', function () {
    $source = ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'shop', 'username' => 'forge', 'password' => "it's secret"];
    $target = ['host' => 'db.cloud', 'port' => '3306', 'database' => 'shop', 'username' => 'cloud', 'password' => 'cloud-secret'];

    $script = DatabaseScripts::copy('mysql', $source, $target);

    expect($script)
        ->toContain("SRC_PASSWORD='it'\\''s secret'")
        ->toContain('MYSQL_PWD="$SRC_PASSWORD" mysqldump --single-transaction --quick --routines --triggers')
        ->toContain('MYSQL_PWD="$DST_PASSWORD" mysql -h "$DST_HOST"')
        ->toContain('EVENTS="--events"')
        ->toContain('--no-tablespaces $EVENTS $GTID')
        ->not->toContain('-p"')
        ->not->toContain('--password');

    expect(DatabaseScripts::copy('mysql', $source, $target, events: false))->toContain('EVENTS="--skip-events"');

    expect(DatabaseScripts::copy('pgsql', $source, $target))
        ->toContain('PGPASSWORD="$SRC_PASSWORD" pg_dump --no-owner --no-privileges')
        ->toContain('PGSSLMODE=require psql');
});

test('row counts are compared table by table', function () {
    $comparison = DatabaseScripts::compareCounts("source\tusers\t10\nsource\torders\t5\nsource\tjobs\t0\ntarget\tusers\t10\ntarget\torders\t4\n");

    expect($comparison['tables'])->toBe(3)
        ->and($comparison['mismatches'])->toBe([
            ['table' => 'orders', 'source' => '5', 'target' => '4'],
            ['table' => 'jobs', 'source' => '0', 'target' => 'missing'],
        ]);
});

test('file surveys are parsed', function () {
    $survey = FileSyncScripts::parseSurvey("dir\tstorage/app/public\t12\t2048\ndir\tstorage/app\t0\t0\ntool:rclone\n");

    expect($survey)->toBe([
        'directories' => [
            'storage/app/public' => ['files' => 12, 'bytes' => 2048],
            'storage/app' => ['files' => 0, 'bytes' => 0],
        ],
        'tools' => ['rclone'],
    ]);
});

test('file sync scripts pass credentials through the environment', function () {
    $bucket = ['bucket' => 'shop-public', 'endpoint' => 'https://r2.example.com', 'key' => 'AKIA', 'secret' => 's3cr3t'];

    expect(FileSyncScripts::rclone('/home/forge/example.com', 'storage/app', ['public', 'private'], $bucket))
        ->toContain("SECRET='s3cr3t'")
        ->toContain('RCLONE_CONFIG_CLOUD_SECRET_ACCESS_KEY="$SECRET"')
        ->toContain("--exclude '/public/**' --exclude '/private/**'")
        ->toContain('"cloud:$BUCKET"');

    expect(FileSyncScripts::aws('/home/forge/example.com', 'storage/app/public', [], $bucket))
        ->toContain('AWS_SECRET_ACCESS_KEY="$SECRET"')
        ->toContain('aws s3 sync \'storage/app/public\' "s3://$BUCKET" --endpoint-url "$ENDPOINT"');
});

test('existing config files load with their env() calls intact', function () {
    $path = tempnam(sys_get_temp_dir(), 'cfg').'.php';
    file_put_contents($path, "<?php\n// env('NOT_A_CALL')\nreturn ['token' => env('LARAVEL_CLOUD_TOKEN'), 'region' => \\env('REGION', 'us-east-2'), 'n' => \$x->env ?? 1];\n");
    putenv('LARAVEL_CLOUD_TOKEN=secret');

    $config = ConfigFile::load($path);

    expect($config['token'])->toBeInstanceOf(EnvExpression::class)
        ->and($config['token']->toPhp())->toBe("env('LARAVEL_CLOUD_TOKEN')")
        ->and($config['region']->toPhp())->toBe("env('REGION', 'us-east-2')")
        ->and(ConfigRenderer::render($config))->not->toContain('secret');

    putenv('LARAVEL_CLOUD_TOKEN');
    unlink($path);
});

test('environment names are suggested from the branch', function () {
    expect(ConfigGenerator::suggestEnvironment('main'))->toBe('production')
        ->and(ConfigGenerator::suggestEnvironment('main', ['production']))->toBe('staging')
        ->and(ConfigGenerator::suggestEnvironment('staging', ['production']))->toBe('staging')
        ->and(ConfigGenerator::suggestEnvironment('feature/Big Thing'))->toBe('feature-big-thing')
        ->and(ConfigGenerator::suggestEnvironment('staging', ['staging']))->toBe('staging-forge');
});
