<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

use Illuminate\Support\Str;

/**
 * Builds a config/cloud.php array from an inspected Forge site.
 */
class ConfigGenerator
{
    /**
     * The environment the Forge site becomes.
     */
    public const ENVIRONMENT = 'production';

    /**
     * Cloud regions, with a label for prompts.
     */
    public const REGIONS = [
        'us-east-2' => 'US East (Ohio)',
        'us-east-1' => 'US East (N. Virginia)',
        'ca-central-1' => 'Canada (Central)',
        'eu-west-1' => 'EU (Ireland)',
        'eu-west-2' => 'EU (London)',
        'eu-central-1' => 'EU (Frankfurt)',
        'me-central-1' => 'Middle East (UAE)',
        'ap-southeast-1' => 'Asia Pacific (Singapore)',
        'ap-southeast-2' => 'Asia Pacific (Sydney)',
        'ap-northeast-1' => 'Asia Pacific (Tokyo)',
    ];

    /**
     * Cloud's PHP versions, oldest first.
     */
    public const PHP_VERSIONS = ['8.2', '8.3', '8.4', '8.5'];

    /**
     * @param  array<string, mixed>  $inspection
     * @param  array{app_name: string, region: string, public_bucket?: bool, private_bucket?: bool}  $plan
     * @return array<string, mixed>
     */
    public static function generate(array $inspection, array $plan): array
    {
        $names = self::resourceNames($plan['app_name']);
        $env = $inspection['env']['facts'] ?? [];
        $integrations = $inspection['integrations'] ?? [];
        $processes = collect($inspection['processes'] ?? []);

        $config = [
            'token' => new EnvExpression('LARAVEL_CLOUD_TOKEN'),
            'forge' => [
                'token' => new EnvExpression('FORGE_API_TOKEN'),
                'organization' => new EnvExpression('FORGE_ORGANIZATION'),
            ],
            'application' => [
                'name' => $plan['app_name'],
                'repository' => $inspection['site']['repository']['full_name'],
                'source_control' => $inspection['site']['repository']['source_control'] ?? 'github',
                'region' => $plan['region'],
            ],
            'environments' => [
                self::ENVIRONMENT => [
                    'branch' => $inspection['site']['repository']['branch'] ?: 'main',
                    'push_to_deploy' => $inspection['site']['quick_deploy'] ?? true,
                    'php' => self::phpVersion($inspection['server']['php_version'] ?? null),
                    'node' => '22',
                    'build_commands' => $inspection['deploy']['build'],
                    'deploy_commands' => $inspection['deploy']['deploy'],
                    'octane' => (bool) (($integrations['octane'] ?? false) || $processes->contains('kind', ProcessClassifier::OCTANE)),
                    'timeout' => 30,
                    'vanity_domain' => true,
                    'instances' => [
                        'App' => [
                            'type' => 'app',
                            'size' => 'flex-512mb',
                            'scaling' => ['type' => 'none', 'min_replicas' => 1, 'max_replicas' => 1],
                            'scheduler' => MigrationReport::usesScheduler($inspection),
                            // Production traffic shouldn't wait for a cold start.
                            'hibernation_timeout' => null,
                            'processes' => self::processes($inspection, $env),
                        ],
                    ] + self::managedQueues($inspection),
                    // Added by the cutover step, once you're ready to move DNS.
                    'domains' => [],
                ],
            ],
            'variables' => [
                'global' => [],
                self::ENVIRONMENT => [],
            ],
            'databases' => [],
            'caches' => [],
            'buckets' => [],
        ];

        $database = $inspection['database'] ?? [];

        if (($database['engine'] ?? null) === 'mysql') {
            $config['databases'][$names['database']] = [
                'type' => 'laravel_mysql',
                'region' => $plan['region'],
                'config' => [
                    'size' => 'mysql-flex-1gb',
                    'storage' => 10,
                    'is_public' => false,
                    'uses_scheduled_snapshots' => true,
                    'retention_days' => 7,
                ],
                'environments' => [self::ENVIRONMENT => self::schemaName($database['name'] ?? null)],
            ];
        } elseif (($database['engine'] ?? null) === 'pgsql') {
            $config['databases'][$names['database']] = [
                'type' => 'neon_serverless_postgres',
                'region' => $plan['region'],
                'config' => [
                    'cu_min' => 0.25,
                    'cu_max' => 1,
                    // Never suspend: production requests shouldn't wait for a cold start.
                    'suspend_seconds' => 0,
                    'retention_days' => 7,
                ],
                'environments' => [self::ENVIRONMENT => self::schemaName($database['name'] ?? null)],
            ];
        }

        if (MigrationReport::usesRedis($env, $integrations)) {
            $config['caches'][$names['cache']] = [
                'type' => 'laravel_valkey',
                'size' => 'valkey-flex-250mb',
                'region' => $plan['region'],
                'auto_upgrade_enabled' => true,
                'is_public' => false,
                'environments' => [self::ENVIRONMENT],
            ];
        }

        if ($plan['public_bucket'] ?? false) {
            $config['buckets'][$names['public_bucket']] = [
                'visibility' => 'public',
                'jurisdiction' => 'default',
                'disk' => 'public',
                'default' => false,
                'environments' => [self::ENVIRONMENT],
            ];
        }

        if ($plan['private_bucket'] ?? false) {
            $config['buckets'][$names['private_bucket']] = [
                'visibility' => 'private',
                'jurisdiction' => 'default',
                'disk' => 's3',
                'default' => true,
                'environments' => [self::ENVIRONMENT],
            ];
        }

        return $config;
    }

    /**
     * The config keys (and Cloud names) used for the app's resources.
     *
     * @return array{database: string, cache: string, public_bucket: string, private_bucket: string}
     */
    public static function resourceNames(string $appName): array
    {
        $slug = Str::slug($appName) ?: 'app';

        return [
            'database' => "{$slug}-db",
            'cache' => "{$slug}-cache",
            'public_bucket' => "{$slug}-public",
            'private_bucket' => "{$slug}-private",
        ];
    }

    /**
     * Map Forge's "php83" to Cloud's "8.3:1", clamped to what Cloud offers.
     */
    public static function phpVersion(?string $forgeVersion): string
    {
        if (! $forgeVersion || ! preg_match('/^php(\d)(\d+)/', $forgeVersion, $matches)) {
            return '8.4:1';
        }

        $version = "{$matches[1]}.{$matches[2]}";
        $oldest = self::PHP_VERSIONS[0];
        $newest = self::PHP_VERSIONS[count(self::PHP_VERSIONS) - 1];

        if (version_compare($version, $oldest, '<')) {
            $version = $oldest;
        } elseif (version_compare($version, $newest, '>')) {
            $version = $newest;
        }

        return "{$version}:1";
    }

    /**
     * Guess the nearest Cloud region to a Forge server's region.
     */
    public static function suggestRegion(?string $forgeRegion): string
    {
        $forgeRegion = strtolower((string) $forgeRegion);

        if (isset(self::REGIONS[$forgeRegion])) {
            return $forgeRegion;
        }

        $prefixes = [
            'nyc' => 'us-east-1', 'ewr' => 'us-east-1', 'ash' => 'us-east-1', 'us-east' => 'us-east-1',
            'sfo' => 'us-east-2', 'hil' => 'us-east-2', 'lax' => 'us-east-2', 'us-west' => 'us-east-2', 'ord' => 'us-east-2', 'dfw' => 'us-east-2',
            'tor' => 'ca-central-1', 'ca-' => 'ca-central-1',
            'lon' => 'eu-west-2', 'lhr' => 'eu-west-2', 'eu-west-2' => 'eu-west-2',
            'ams' => 'eu-west-1', 'dub' => 'eu-west-1', 'eu-west' => 'eu-west-1', 'par' => 'eu-west-1', 'cdg' => 'eu-west-1',
            'fra' => 'eu-central-1', 'fsn' => 'eu-central-1', 'nbg' => 'eu-central-1', 'hel' => 'eu-central-1', 'eu-' => 'eu-central-1',
            'sgp' => 'ap-southeast-1', 'sin' => 'ap-southeast-1', 'blr' => 'ap-southeast-1', 'bom' => 'ap-southeast-1',
            'syd' => 'ap-southeast-2', 'mel' => 'ap-southeast-2',
            'nrt' => 'ap-northeast-1', 'tok' => 'ap-northeast-1', 'ap-northeast' => 'ap-northeast-1',
            'me-' => 'me-central-1',
        ];

        foreach ($prefixes as $prefix => $region) {
            if (str_starts_with($forgeRegion, $prefix)) {
                return $region;
            }
        }

        return 'us-east-2';
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  array<string, string>  $env
     * @return array<string, array<string, mixed>>
     */
    protected static function processes(array $inspection, array $env): array
    {
        $processes = [];
        $defaultConnection = $env['QUEUE_CONNECTION'] ?? 'database';
        $hasHorizon = false;
        $keptWorkers = array_column(QueuePlanner::plan($inspection)['workers'], 'id');

        foreach ($inspection['processes'] ?? [] as $process) {
            switch ($process['kind']) {
                case ProcessClassifier::WORKER:
                    // Most workers become managed queues; only the ones
                    // managed queues can't serve stay on the app instance.
                    if (! in_array($process['id'], $keptWorkers, true)) {
                        break;
                    }

                    $queue = $process['queue'];
                    $name = self::uniqueName($processes, 'worker-'.Str::slug(implode('-', $queue['queues'])));
                    $processes[$name] = [
                        'type' => 'worker',
                        'processes' => $process['processes'],
                        'queue' => [
                            'connection' => $queue['connection'] ?? $defaultConnection,
                            'queues' => $queue['queues'],
                            // Laravel's own defaults for anything the Forge worker didn't set.
                            'tries' => $queue['tries'] ?? 1,
                            'backoff' => $queue['backoff'] ?? 0,
                            'timeout' => $queue['timeout'] ?? 60,
                            'sleep' => $queue['sleep'] ?? 3,
                            'rest' => $queue['rest'] ?? 0,
                            'force' => $queue['force'],
                        ],
                    ];
                    break;

                case ProcessClassifier::HORIZON:
                    $hasHorizon = true;
                    $processes['horizon'] = ['type' => 'custom', 'processes' => 1, 'command' => 'php artisan horizon'];
                    break;

                case ProcessClassifier::ARTISAN:
                    $name = self::uniqueName($processes, Str::slug(str_replace(':', '-', strtok((string) $process['artisan'], ' ') ?: 'daemon')));
                    $processes[$name] = [
                        'type' => 'custom',
                        'processes' => $process['processes'],
                        'command' => 'php artisan '.$process['artisan'],
                    ];
                    break;
            }
        }

        if (! $hasHorizon && ($inspection['integrations']['horizon'] ?? false)) {
            $processes['horizon'] = ['type' => 'custom', 'processes' => 1, 'command' => 'php artisan horizon'];
        }

        return $processes;
    }

    /**
     * Managed queue instances, keyed by queue name.
     *
     * @param  array<string, mixed>  $inspection
     * @return array<string, array<string, mixed>>
     */
    protected static function managedQueues(array $inspection): array
    {
        $instances = [];

        foreach (QueuePlanner::plan($inspection)['managed'] as $name => $queue) {
            $instances[$name] = [
                'type' => 'managed_queue',
                'size' => $queue['size'],
                'scaling' => ['type' => 'custom', 'min_replicas' => 0, 'max_replicas' => $queue['max_replicas']],
            ];
        }

        return $instances;
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    protected static function uniqueName(array $existing, string $name): string
    {
        $candidate = $name;

        for ($i = 2; isset($existing[$candidate]); $i++) {
            $candidate = "{$name}-{$i}";
        }

        return $candidate;
    }

    /**
     * Keep the Forge database name when Cloud will accept it.
     */
    protected static function schemaName(?string $name): string
    {
        return $name && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $name) ? $name : 'laravel';
    }
}
