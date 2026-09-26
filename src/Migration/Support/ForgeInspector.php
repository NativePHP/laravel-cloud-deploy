<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

use Illuminate\Http\Client\RequestException;
use NativePhp\LaravelCloudDeploy\ForgeClient;

/**
 * Reads everything the migration needs to know about a Forge site.
 *
 * The result is saved to the state file, which people commit, so it only
 * holds facts about the setup. Secrets (the .env values, the deploy
 * script itself) are read again from Forge when a step needs them.
 */
class ForgeInspector
{
    /**
     * .env keys whose values are safe to keep and that later steps use.
     */
    protected const ENV_FACTS = [
        'APP_NAME', 'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'CACHE_STORE', 'CACHE_DRIVER',
        'QUEUE_CONNECTION', 'SESSION_DRIVER', 'FILESYSTEM_DISK', 'FILESYSTEM_DRIVER',
        'BROADCAST_CONNECTION', 'BROADCAST_DRIVER', 'LOG_CHANNEL',
    ];

    protected const INTEGRATIONS = ['horizon', 'octane', 'reverb', 'laravel-scheduler', 'pulse'];

    public function __construct(
        protected ForgeClient $forge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $serverId, string $siteId, string $projectPath): array
    {
        $server = $this->forge->getServer($serverId)['data'] ?? [];
        $site = $this->forge->getSite($siteId)['data'] ?? [];
        $serverAttributes = $server['attributes'] ?? [];
        $siteAttributes = $site['attributes'] ?? [];

        $env = EnvFile::parse($this->forge->getEnvironmentFile($serverId, $siteId));
        $sitePath = '/home/'.($siteAttributes['user'] ?? 'forge').'/'.($siteAttributes['name'] ?? '');

        $integrations = $this->integrations($serverId, $siteId);

        return [
            'server' => [
                'id' => (string) ($server['id'] ?? $serverId),
                'name' => $serverAttributes['name'] ?? null,
                'ip_address' => $serverAttributes['ip_address'] ?? null,
                'ssh_port' => (int) ($serverAttributes['ssh_port'] ?? 22),
                'php_version' => $serverAttributes['php_version'] ?? null,
                'database_type' => $serverAttributes['database_type'] ?? null,
                'region' => $serverAttributes['region'] ?? null,
                'provider' => $serverAttributes['provider'] ?? null,
            ],
            'site' => [
                'id' => (string) ($site['id'] ?? $siteId),
                'name' => $siteAttributes['name'] ?? null,
                'user' => $siteAttributes['user'] ?? 'forge',
                'path' => $sitePath,
                'app_type' => $siteAttributes['app_type'] ?? null,
                'web_directory' => $siteAttributes['web_directory'] ?? '/public',
                'root_directory' => $siteAttributes['root_directory'] ?? null,
                'zero_downtime' => (bool) ($siteAttributes['zero_downtime_deployments'] ?? false),
                'quick_deploy' => $siteAttributes['quick_deploy'] ?? null,
                'repository' => [
                    'provider' => $siteAttributes['repository']['provider'] ?? null,
                    'url' => $siteAttributes['repository']['url'] ?? null,
                    'branch' => $siteAttributes['repository']['branch'] ?? null,
                    'full_name' => self::repositoryFullName($siteAttributes['repository']['url'] ?? null),
                ],
            ],
            'env' => [
                'keys' => array_keys($env),
                'facts' => array_intersect_key($env, array_flip(self::ENV_FACTS)),
                'has_nightwatch' => ($env['NIGHTWATCH_TOKEN'] ?? '') !== '',
            ],
            'deploy' => DeployScriptParser::parse($this->forge->getDeploymentScript($serverId, $siteId)),
            'processes' => $this->processes($serverId, $siteId, $sitePath),
            'scheduled_jobs' => $this->scheduledJobs($serverId, $siteId),
            'integrations' => $integrations,
            'database' => $this->database($env, $serverAttributes, $serverId),
            'domains' => array_map(fn (array $domain) => [
                'name' => $domain['attributes']['name'] ?? null,
                'type' => $domain['attributes']['type'] ?? null,
                'www_redirect_type' => $domain['attributes']['www_redirect_type'] ?? 'none',
                'wildcard' => (bool) ($domain['attributes']['allow_wildcard_subdomains'] ?? false),
            ], $this->forge->listDomains($serverId, $siteId)),
            'nginx' => ['custom_directives' => self::customNginxDirectives($this->optional(
                fn () => $this->forge->getNginxConfig($serverId, $siteId), ''
            ), $integrations)],
            'redirects' => $this->optional(fn () => array_map(fn (array $rule) => [
                'from' => $rule['attributes']['from'] ?? null,
                'to' => $rule['attributes']['to'] ?? null,
                'type' => $rule['attributes']['type'] ?? null,
            ], $this->forge->listRedirectRules($serverId, $siteId))),
            'security_rules' => $this->optional(fn () => array_map(fn (array $rule) => [
                'name' => $rule['attributes']['name'] ?? null,
                'path' => $rule['attributes']['path'] ?? null,
            ], $this->forge->listSecurityRules($serverId, $siteId))),
            'local_disk_usage' => LocalDiskScanner::scan($projectPath),
            'project' => self::projectFacts($projectPath),
        ];
    }

    /**
     * What the local project's composer.lock says, for features that need
     * a minimum Laravel version or an extra package.
     *
     * @return array{laravel_version: string|null, has_aws_sdk: bool|null}
     */
    public static function projectFacts(string $projectPath): array
    {
        $lock = rtrim($projectPath, '/').'/composer.lock';

        if (! is_file($lock)) {
            return ['laravel_version' => null, 'has_aws_sdk' => null];
        }

        $packages = collect(json_decode((string) file_get_contents($lock), true)['packages'] ?? []);

        return [
            'laravel_version' => ($version = $packages->firstWhere('name', 'laravel/framework')['version'] ?? null) ? ltrim($version, 'v') : null,
            'has_aws_sdk' => $packages->contains('name', 'aws/aws-sdk-php'),
        ];
    }

    /**
     * Turn a repository URL or "owner/repo" into "owner/repo".
     */
    public static function repositoryFullName(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $url = trim($url);

        if (preg_match('#^(?:git@[^:]+:|https?://[^/]+/|ssh://git@[^/]+/)?([\w.-]+/[\w.-]+?)(?:\.git)?/?$#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Pick out Nginx directives that go beyond Forge's defaults.
     *
     * @param  array<string, mixed>  $integrations
     * @return array<int, string>
     */
    public static function customNginxDirectives(string $config, array $integrations = []): array
    {
        $defaultLocations = [
            'location /', 'location = /favicon.ico', 'location = /robots.txt',
            'location ~ \.php$', 'location ~ /\.(?!well-known).*', 'location /index.php',
        ];

        $custom = [];

        foreach (preg_split('/\r\n|\n/', $config) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^location\s+(.+?)\s*\{?$/', $line, $matches)) {
                if (! in_array('location '.$matches[1], $defaultLocations, true)) {
                    $custom[] = $line;
                }

                continue;
            }

            if (preg_match('/^(rewrite|return|auth_basic|client_max_body_size|limit_req|allow|deny|expires|gzip_types)\b/', $line)) {
                $custom[] = $line;

                continue;
            }

            // Octane and Reverb sites proxy to a local port; that's expected.
            if (str_starts_with($line, 'proxy_pass') && ! ($integrations['octane'] ?? false) && ! ($integrations['reverb'] ?? false)) {
                $custom[] = $line;
            }
        }

        return array_values(array_unique($custom));
    }

    /**
     * @return array<string, bool|int|null>
     */
    protected function integrations(string $serverId, string $siteId): array
    {
        $result = [];

        foreach (self::INTEGRATIONS as $name) {
            $integration = $this->optional(fn () => $this->forge->getIntegration($serverId, $siteId, $name));
            $attributes = $integration['data']['attributes'] ?? [];
            $key = $name === 'laravel-scheduler' ? 'scheduler' : $name;

            // Forge returns "enabled" as a boolean for some integrations and a string for others.
            $result[$key] = filter_var($attributes['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || ($attributes['enabled'] ?? null) === 'enabled';

            if ($name === 'octane') {
                $result['octane_port'] = $attributes['port'] ?? null;
            }
        }

        return $result;
    }

    /**
     * The site's background processes.
     *
     * Processes made through the site's worker and integration screens carry
     * the site ID. Older ones made as server daemons don't, so also take any
     * server process that runs in or points at the site's directory.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function processes(string $serverId, string $siteId, string $sitePath): array
    {
        $processes = [];

        foreach ($this->forge->listBackgroundProcesses($serverId, $siteId) as $process) {
            $processes[(string) $process['id']] = $process;
        }

        foreach ($this->forge->listBackgroundProcesses($serverId) as $process) {
            $command = (string) ($process['attributes']['command'] ?? '');
            $directory = (string) ($process['attributes']['directory'] ?? '');

            if (str_starts_with($directory, $sitePath) || str_contains($command, $sitePath.'/')) {
                $processes[(string) $process['id']] = $process;
            }
        }

        return array_values(array_map(function (array $process) {
            $command = (string) ($process['attributes']['command'] ?? '');
            $kind = ProcessClassifier::classify($command);

            return [
                'id' => (string) $process['id'],
                'command' => $command,
                'processes' => (int) ($process['attributes']['processes'] ?? 1),
                'kind' => $kind,
                'artisan' => ProcessClassifier::artisanCommand($command),
                'queue' => $kind === ProcessClassifier::WORKER ? ProcessClassifier::parseWorker($command) : null,
            ];
        }, $processes));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function scheduledJobs(string $serverId, string $siteId): array
    {
        return array_map(function (array $job) {
            $command = (string) ($job['attributes']['command'] ?? '');

            return [
                'command' => $command,
                'cron' => $job['attributes']['cron'] ?? null,
                'kind' => ProcessClassifier::isScheduler($command)
                    ? 'scheduler'
                    : (ProcessClassifier::artisanCommand($command) !== null ? 'artisan' : 'other'),
            ];
        }, $this->forge->listSiteScheduledJobs($serverId, $siteId));
    }

    /**
     * @param  array<string, string>  $env
     * @param  array<string, mixed>  $serverAttributes
     * @return array<string, mixed>
     */
    protected function database(array $env, array $serverAttributes, string $serverId): array
    {
        $serverType = $serverAttributes['database_type'] ?? null;
        $connection = $env['DB_CONNECTION'] ?? null;

        $engine = match (true) {
            in_array($connection, ['mysql', 'mariadb'], true) => 'mysql',
            $connection === 'pgsql' => 'pgsql',
            $connection !== null && $connection !== '' => $connection,
            str_starts_with((string) $serverType, 'mysql'), str_starts_with((string) $serverType, 'mariadb') => 'mysql',
            str_starts_with((string) $serverType, 'postgres') => 'pgsql',
            default => null,
        };

        $host = $env['DB_HOST'] ?? '127.0.0.1';

        return [
            'engine' => $engine,
            'flavour' => $connection === 'mariadb' || str_starts_with((string) $serverType, 'mariadb') ? 'mariadb' : $engine,
            'server_type' => $serverType,
            'name' => $env['DB_DATABASE'] ?? null,
            'host_is_local' => in_array($host, ['127.0.0.1', 'localhost', '::1'], true),
            'schemas' => $this->optional(fn () => array_map(
                fn (array $schema) => $schema['attributes']['name'] ?? null,
                $this->forge->listDatabaseSchemas($serverId)
            ), []),
        ];
    }

    /**
     * Run a read that's nice to have, returning a fallback when Forge refuses
     * it (e.g. the token lacks that scope) or it doesn't exist.
     */
    protected function optional(callable $read, mixed $fallback = null): mixed
    {
        try {
            return $read();
        } catch (RequestException $e) {
            if (in_array($e->response->status(), [403, 404], true)) {
                return $fallback;
            }

            throw $e;
        }
    }
}
