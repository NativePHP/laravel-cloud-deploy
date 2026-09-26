<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Tests\Support;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Forge and Cloud APIs with responses shaped like their OpenAPI specs.
 *
 * Routes are "METHOD /path" (without the /api prefix) mapped to a response
 * array, an Http::response(), or a closure taking the request. Forge paths
 * are relative to /orgs/acme when they don't start with /orgs.
 */
class FakeApis
{
    public const ORG = 'acme';

    public const SERVER = '101';

    public const SITE = '202';

    /**
     * @var array<string, mixed>
     */
    protected static array $forge = [];

    /**
     * @var array<string, mixed>
     */
    protected static array $cloud = [];

    protected static ?object $factory = null;

    /**
     * @param  array<string, mixed>  $forge  Overrides for the default Forge routes
     * @param  array<string, mixed>  $cloud  Overrides for the default Cloud routes
     */
    public static function fake(array $forge = [], array $cloud = []): void
    {
        // Calling fake() again replaces the routes rather than stacking a
        // second fake behind the first, which would never be reached.
        self::$forge = array_merge(self::forgeRoutes(), self::prefixForge($forge));
        self::$cloud = array_merge(self::cloudRoutes(), $cloud);

        $factory = Http::getFacadeRoot();

        if (self::$factory === $factory) {
            return;
        }

        self::$factory = $factory;

        Http::fake(function (Request $request) {
            $url = parse_url($request->url());
            $path = preg_replace('#^/api#', '', $url['path'] ?? '');
            $key = $request->method().' '.$path;
            $isForge = str_contains($url['host'] ?? '', 'forge');
            $routes = $isForge ? self::$forge : self::$cloud;

            if (! array_key_exists($key, $routes)) {
                return $isForge
                    ? Http::response(['message' => "No fake for {$key}"], 404)
                    : Http::response(['data' => []]);
            }

            $route = $routes[$key];

            if ($route instanceof Closure) {
                $route = $route($request);
            }

            return is_array($route) ? Http::response($route) : $route;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function forgeRoutes(): array
    {
        $site = '/orgs/acme/servers/101/sites/202';

        return [
            'GET /orgs' => self::page([self::resource('organizations', '1', ['name' => 'Acme', 'slug' => 'acme', 'created_at' => null, 'updated_at' => null])]),
            'GET /orgs/acme/servers' => self::page([self::server()]),
            'GET /orgs/acme/servers/101' => ['data' => self::server()],
            'GET /orgs/acme/servers/101/sites' => self::page([self::site()]),
            'GET /orgs/acme/sites/202' => ['data' => self::site()],
            "GET {$site}/environment" => ['data' => self::resource('environments', '202', ['content' => self::env()])],
            "GET {$site}/deployments/script" => ['data' => self::resource('deploymentScripts', '202', ['content' => self::deployScript(), 'auto_source' => false])],
            "GET {$site}/nginx" => ['data' => self::resource('nginxConfigs', '202', ['content' => self::nginx()])],
            'GET /orgs/acme/servers/101/background-processes' => self::page([]),
            "GET {$site}/scheduled-jobs" => self::page([]),
            "GET {$site}/domains" => self::page([
                self::resource('domainRecords', '301', [
                    'name' => 'example.com', 'type' => 'primary', 'status' => 'enabled',
                    'www_redirect_type' => 'from-www', 'allow_wildcard_subdomains' => false,
                    'created_at' => '2024-01-01T00:00:00Z', 'updated_at' => '2024-01-01T00:00:00Z',
                ]),
            ]),
            "GET {$site}/redirect-rules" => self::page([]),
            "GET {$site}/security-rules" => self::page([]),
            "GET {$site}/integrations/horizon" => self::integration('horizonIntegrations', ['enabled' => 'false', 'horizon_installed' => false]),
            "GET {$site}/integrations/octane" => self::integration('octaneIntegrations', ['enabled' => 'false', 'octane_installed' => false, 'port' => null]),
            "GET {$site}/integrations/reverb" => self::integration('reverbIntegrations', ['enabled' => 'false', 'reverb_installed' => false, 'host' => null, 'port' => null, 'connections' => null]),
            "GET {$site}/integrations/laravel-scheduler" => self::integration('laravelSchedulerIntegrations', ['enabled' => false, 'laravel_installed' => true]),
            "GET {$site}/integrations/pulse" => self::integration('pulseIntegrations', ['enabled' => 'false']),
            'GET /orgs/acme/servers/101/database/schemas' => self::page([
                self::resource('databases', '401', ['name' => 'shop', 'status' => 'installed', 'created_at' => '2024-01-01T00:00:00Z', 'updated_at' => '2024-01-01T00:00:00Z']),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cloudRoutes(): array
    {
        return [
            'GET /meta/organization' => ['data' => ['id' => 'org-1', 'type' => 'organizations', 'attributes' => ['name' => 'Acme Cloud', 'slug' => 'acme']]],
            'GET /applications' => ['data' => [], 'links' => ['next' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function server(array $attributes = []): array
    {
        return self::resource('servers', self::SERVER, array_merge([
            'id' => 101, 'credential_id' => 1, 'name' => 'web-1', 'slug' => 'web-1', 'type' => 'app',
            'ubuntu_version' => '24.04', 'ssh_port' => 22, 'provider' => 'ocean2', 'identifier' => null,
            'size' => 's-1vcpu-1gb', 'region' => 'lon1', 'php_version' => 'php83', 'php_cli_version' => 'php83',
            'opcache_status' => null, 'database_type' => 'mysql8', 'db_status' => null, 'redis_status' => null,
            'ip_address' => '203.0.113.10', 'private_ip_address' => '10.0.0.2', 'revoked' => false,
            'created_at' => '2024-01-01T00:00:00Z', 'updated_at' => '2024-01-01T00:00:00Z',
            'connection_status' => 'successful', 'timezone' => 'UTC', 'local_public_key' => null, 'is_ready' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function site(array $attributes = []): array
    {
        return self::resource('sites', self::SITE, array_merge([
            'name' => 'example.com', 'status' => 'installed', 'url' => 'https://example.com', 'user' => 'forge',
            'https' => true, 'web_directory' => '/public', 'root_directory' => '/', 'aliases' => [],
            'php_version' => null, 'deployment_status' => 'finished', 'quick_deploy' => true, 'isolated' => false,
            'shared_paths' => [],
            'repository' => ['provider' => 'github', 'url' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'status' => 'installed'],
            'database' => 'shop', 'maintenance_mode' => ['enabled' => false, 'status' => null],
            'zero_downtime_deployments' => false, 'deployment_retention' => null, 'deployment_script' => null,
            'wildcards' => false, 'app_type' => 'Laravel', 'uses_envoyer' => false,
            'deployment_url' => 'https://forge.laravel.com/deploy/x', 'healthcheck_url' => null,
            'created_at' => '2024-01-01T00:00:00Z', 'updated_at' => '2024-01-01T00:00:00Z',
        ], $attributes));
    }

    /**
     * A Forge background process.
     *
     * @return array<string, mixed>
     */
    public static function process(string $id, string $command, int $processes = 1): array
    {
        return self::resource('backgroundProcesses', $id, [
            'command' => $command, 'user' => 'forge', 'directory' => null, 'processes' => $processes,
            'status' => 'running', 'created_at' => '2024-01-01T00:00:00Z',
        ]);
    }

    /**
     * A Forge scheduled job.
     *
     * @return array<string, mixed>
     */
    public static function job(string $id, string $command, string $cron = '* * * * *'): array
    {
        return self::resource('scheduledJobs', $id, [
            'name' => null, 'command' => $command, 'status' => 'installed', 'user' => 'forge',
            'frequency' => 'minutely', 'cron' => $cron, 'next_run_time' => '2024-01-01T00:01:00Z',
            'created_at' => null, 'updated_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function integration(string $type, array $attributes): array
    {
        return ['data' => self::resource($type, '202', $attributes)];
    }

    public static function env(array $overrides = []): string
    {
        $values = array_merge([
            'APP_NAME' => 'Shop',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:c2VjcmV0',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://example.com',
            'LOG_CHANNEL' => 'stack',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'shop',
            'DB_USERNAME' => 'forge',
            'DB_PASSWORD' => 'forge-db-secret',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'SESSION_DRIVER' => 'database',
            'FILESYSTEM_DISK' => 'local',
            'MAIL_MAILER' => 'postmark',
            'POSTMARK_TOKEN' => 'pm-secret',
        ], $overrides);

        return collect($values)
            ->reject(fn ($value) => $value === null)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode("\n")."\n";
    }

    public static function deployScript(): string
    {
        return <<<'SH'
cd /home/forge/example.com
git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

if [ -f artisan ]; then
    $FORGE_PHP artisan migrate --force
fi
SH;
    }

    public static function nginx(): string
    {
        return <<<'NGINX'
server {
    listen 443 ssl http2;
    server_name example.com;
    root /home/forge/example.com/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    /**
     * A JSON:API list page with cursor pagination meta.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function page(array $items, ?string $nextCursor = null): array
    {
        return [
            'data' => $items,
            'links' => ['next' => $nextCursor ? "/api/orgs/acme/x?page[cursor]={$nextCursor}" : null],
            'meta' => ['path' => null, 'per_page' => 30, 'next_cursor' => $nextCursor, 'prev_cursor' => null],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function resource(string $type, string $id, array $attributes): array
    {
        return ['id' => $id, 'type' => $type, 'attributes' => $attributes, 'links' => ['self' => ['href' => "/{$type}/{$id}"]]];
    }

    /**
     * @param  array<string, mixed>  $routes
     * @return array<string, mixed>
     */
    protected static function prefixForge(array $routes): array
    {
        $prefixed = [];

        foreach ($routes as $key => $route) {
            [$method, $path] = explode(' ', $key, 2);

            if (! str_starts_with($path, '/orgs')) {
                $path = '/orgs/acme'.$path;
            }

            $prefixed["{$method} {$path}"] = $route;
        }

        return $prefixed;
    }
}
