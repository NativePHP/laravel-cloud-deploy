<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Sorts what we found on Forge into what migrates, what needs a person,
 * and what stops the migration.
 */
class MigrationReport
{
    public const MIGRATES = 'migrates';

    public const MANUAL = 'manual';

    public const BLOCKER = 'blocker';

    /**
     * The oldest PHP version Cloud runs.
     */
    public const MIN_PHP = '8.2';

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<int, array{status: string, area: string, detail: string}>
     */
    public static function build(array $inspection): array
    {
        $rows = [];
        $add = function (string $status, string $area, string $detail) use (&$rows) {
            $rows[] = ['status' => $status, 'area' => $area, 'detail' => $detail];
        };

        $site = $inspection['site'];
        $env = $inspection['env']['facts'] ?? [];
        $integrations = $inspection['integrations'] ?? [];

        // Application type
        $appType = (string) ($site['app_type'] ?? '');

        if (strtolower($appType) === 'laravel') {
            $add(self::MIGRATES, 'Application', 'Laravel site '.$site['name']);
        } else {
            $add(self::BLOCKER, 'Application', 'Forge lists this site as "'.($appType ?: 'unknown').'". This tool only migrates Laravel sites.');
        }

        // Repository
        $repository = $site['repository'];

        if (! $repository['url']) {
            $add(self::BLOCKER, 'Repository', 'The site has no Git repository. Cloud deploys from GitHub, so push the code to a GitHub repository and connect it in Forge first.');
        } elseif ($repository['provider'] !== 'github' || ! $repository['full_name']) {
            $add(self::BLOCKER, 'Repository', 'The repository is on "'.$repository['provider'].'". This tool needs the repository on GitHub.');
        } else {
            $add(self::MIGRATES, 'Repository', $repository['full_name'].' on branch '.($repository['branch'] ?: 'main'));
        }

        // PHP
        $php = ConfigGenerator::phpVersion($inspection['server']['php_version'] ?? null);
        $forgePhp = self::forgePhp($inspection['server']['php_version'] ?? null);

        if ($forgePhp !== null && version_compare($forgePhp, self::MIN_PHP, '<')) {
            $add(self::MANUAL, 'PHP', "Forge runs PHP {$forgePhp}; Cloud's oldest is ".self::MIN_PHP.'. The app will run on '.$php.'. Test it on 8.2+ before cutting over.');
        } else {
            $add(self::MIGRATES, 'PHP', 'PHP '.$php);
        }

        if (($site['web_directory'] ?? '/public') !== '/public') {
            $add(self::MANUAL, 'Web directory', 'The site serves from '.$site['web_directory'].'. Cloud serves Laravel from /public.');
        }

        // Deploy script
        $deploy = $inspection['deploy'];
        $add(self::MIGRATES, 'Build commands', implode(' && ', $deploy['build']));
        $add(self::MIGRATES, 'Deploy commands', $deploy['deploy'] === [] ? 'none' : implode(' && ', $deploy['deploy']));

        foreach ($deploy['unmapped'] as $line) {
            $add(self::MANUAL, 'Deploy script', "Not carried over: {$line}");
        }

        // Database
        $database = $inspection['database'];

        match ($database['engine']) {
            'mysql' => $add(self::MIGRATES, 'Database', 'MySQL database '.($database['name'] ?? '').' to a Laravel MySQL cluster'
                .($database['flavour'] === 'mariadb' ? ' (MariaDB dumps usually import fine, but test it)' : '')),
            'pgsql' => $add(self::MIGRATES, 'Database', 'Postgres database '.($database['name'] ?? '').' to Laravel Serverless Postgres'),
            null => $add(self::MIGRATES, 'Database', 'No database found; none will be created'),
            'sqlite' => $add(self::BLOCKER, 'Database', 'The app uses SQLite. Cloud\'s filesystem is rebuilt on every deploy, so a SQLite file would be lost. Move to MySQL or Postgres first.'),
            default => $add(self::BLOCKER, 'Database', 'The app uses the "'.$database['engine'].'" driver, which Cloud doesn\'t offer.'),
        };

        if (in_array($database['engine'], ['mysql', 'pgsql'], true) && ! $database['host_is_local']) {
            $add(self::MANUAL, 'Database', 'DB_HOST points away from the Forge server ('.($env['DB_HOST'] ?? '').'). The copy runs from the Forge server, so it must be able to reach that host.');
        }

        // Cache and queues
        if (self::usesRedis($env, $integrations)) {
            $add(self::MIGRATES, 'Cache', 'Redis is used for cache, queues, sessions or Horizon; a Laravel Valkey cache will be attached');
        }

        foreach ($inspection['processes'] as $process) {
            $label = "{$process['processes']} x {$process['command']}";

            match ($process['kind']) {
                ProcessClassifier::WORKER => $add(self::MIGRATES, 'Queue worker', $label),
                ProcessClassifier::HORIZON => $add(self::MIGRATES, 'Horizon', 'runs as a background process on Cloud'),
                ProcessClassifier::OCTANE => $add(self::MIGRATES, 'Octane', 'Cloud runs Octane itself; the Octane setting will be turned on'),
                ProcessClassifier::REVERB => $add(self::MANUAL, 'Reverb', 'Cloud offers managed WebSocket servers for Reverb. Set one up in the dashboard and attach it.'),
                ProcessClassifier::ARTISAN => $add(self::MIGRATES, 'Daemon', $label),
                default => $add(self::MANUAL, 'Daemon', "Not carried over (not an artisan command): {$label}"),
            };
        }

        if (($integrations['octane'] ?? false) && ! collect($inspection['processes'])->contains('kind', ProcessClassifier::OCTANE)) {
            $add(self::MIGRATES, 'Octane', 'Cloud runs Octane itself; the Octane setting will be turned on');
        }

        if (($integrations['horizon'] ?? false) && ! collect($inspection['processes'])->contains('kind', ProcessClassifier::HORIZON)) {
            $add(self::MIGRATES, 'Horizon', 'runs as a background process on Cloud');
        }

        // Scheduler and cron
        foreach ($inspection['scheduled_jobs'] as $job) {
            match ($job['kind']) {
                'scheduler' => null,
                'artisan' => $add(self::MANUAL, 'Scheduled job', "Cloud only runs the Laravel scheduler. Move \"{$job['command']}\" ({$job['cron']}) into routes/console.php."),
                default => $add(self::MANUAL, 'Scheduled job', "Not carried over: {$job['command']} ({$job['cron']})"),
            };
        }

        if (self::usesScheduler($inspection)) {
            $add(self::MIGRATES, 'Scheduler', 'schedule:run is turned on for the app instance');
        }

        // Files
        $disk = $env['FILESYSTEM_DISK'] ?? $env['FILESYSTEM_DRIVER'] ?? 'local';

        if ($disk === 's3') {
            $add(self::MIGRATES, 'Files', 'The app already stores files on S3; they stay where they are');
        } else {
            $add(self::MIGRATES, 'Files', 'Files in storage/app are copied to Cloud object storage');

            foreach ($inspection['local_disk_usage'] as $match) {
                $add(self::MANUAL, 'Local disk', 'Cloud\'s disk is wiped on deploy and not shared between replicas. Point this at the bucket disk: '.$match);
            }
        }

        // Web server rules
        foreach ($inspection['nginx']['custom_directives'] as $directive) {
            $add(self::MANUAL, 'Nginx', "Cloud doesn't use your Nginx config. Recreate this in the app or Cloud's edge settings: {$directive}");
        }

        if ($inspection['redirects'] === null) {
            $add(self::MANUAL, 'Redirects', 'Couldn\'t read redirect rules (the token needs the site:manage-redirects scope). Check them in Forge.');
        }

        foreach ($inspection['redirects'] ?? [] as $rule) {
            $add(self::MANUAL, 'Redirect', "{$rule['from']} -> {$rule['to']} ({$rule['type']}). Add it as a route or middleware in the app.");
        }

        if ($inspection['security_rules'] === null) {
            $add(self::MANUAL, 'Basic auth', 'Couldn\'t read security rules (the token needs the site:manage-security scope). Check them in Forge.');
        }

        foreach ($inspection['security_rules'] ?? [] as $rule) {
            $add(self::MANUAL, 'Basic auth', 'Password protection on '.($rule['path'] ?: '/').' isn\'t carried over. Use middleware in the app instead.');
        }

        // Environment and domains
        $add(self::MIGRATES, 'Environment', count($inspection['env']['keys']).' variables, minus the ones Cloud injects');

        foreach ($inspection['domains'] as $domain) {
            $add(self::MIGRATES, 'Domain', "{$domain['name']} ({$domain['type']}), added to Cloud during cutover");
        }

        return $rows;
    }

    /**
     * @param  array<int, array{status: string, area: string, detail: string}>  $rows
     */
    public static function hasBlockers(array $rows): bool
    {
        return collect($rows)->contains('status', self::BLOCKER);
    }

    /**
     * @param  array<string, string>  $env
     * @param  array<string, mixed>  $integrations
     */
    public static function usesRedis(array $env, array $integrations): bool
    {
        foreach (['CACHE_STORE', 'CACHE_DRIVER', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'BROADCAST_CONNECTION', 'BROADCAST_DRIVER'] as $key) {
            if (($env[$key] ?? null) === 'redis') {
                return true;
            }
        }

        return (bool) ($integrations['horizon'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    public static function usesScheduler(array $inspection): bool
    {
        return ($inspection['integrations']['scheduler'] ?? false)
            || collect($inspection['scheduled_jobs'])->contains('kind', 'scheduler');
    }

    /**
     * "php83" -> "8.3"
     */
    protected static function forgePhp(?string $version): ?string
    {
        if ($version && preg_match('/^php(\d)(\d+)/', $version, $matches)) {
            return "{$matches[1]}.{$matches[2]}";
        }

        return null;
    }
}
