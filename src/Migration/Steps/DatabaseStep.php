<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Remote\RemoteShell;
use NativePhp\LaravelCloudDeploy\Migration\Remote\SshAccess;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;
use NativePhp\LaravelCloudDeploy\Migration\Support\DatabaseScripts;
use NativePhp\LaravelCloudDeploy\Migration\Support\EnvFile;
use NativePhp\LaravelCloudDeploy\Migration\Support\Spinner;

use function Laravel\Prompts\confirm;

/**
 * Copies the Forge database into the Cloud database, server to server.
 */
class DatabaseStep extends Step
{
    public const CLEANUP = 'database_public';

    /**
     * Seconds between checks while a cluster applies a settings change.
     */
    public static int $pollSeconds = 10;

    /**
     * How many checks before giving up on a cluster settings change.
     */
    public static int $attempts = 90;

    public function name(): string
    {
        return 'database';
    }

    public function title(): string
    {
        return 'Copy the database';
    }

    public function explanation(): string
    {
        return 'Copy the database from the Forge server to Cloud. A temporary SSH key is added to the server, and a Laravel MySQL '
            .'cluster\'s public endpoint is switched on for the copy. The dump streams from the Forge server straight into Cloud, '
            .'so nothing is written to disk. Afterwards row counts are compared, the endpoint is switched off and the key removed. '
            .'Tables in Cloud with the same names are replaced. Nothing on Forge is changed.';
    }

    public function handle(MigrationContext $context): bool
    {
        if (($context->inspection()['database']['engine'] ?? null) === null) {
            $this->success('The site has no database, so there is nothing to copy.');

            return true;
        }

        if (! confirm('Copy the database to Cloud now? The site keeps running on Forge while it copies.')) {
            return false;
        }

        return $this->copy($context);
    }

    /**
     * Copy the database and verify it. Used by this step and by cutover.
     */
    public function copy(MigrationContext $context): bool
    {
        $inspection = $context->inspection();
        $engine = $inspection['database']['engine'];
        $target = $this->cloudDatabase($context);

        $env = EnvFile::parse($context->forge()->getEnvironmentFile(
            (string) $context->get('forge.server_id'),
            (string) $context->get('forge.site_id'),
        ));

        $source = [
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? ($engine === 'pgsql' ? '5432' : '3306'),
            'database' => $env['DB_DATABASE'] ?? $inspection['database']['name'] ?? '',
            'username' => $env['DB_USERNAME'] ?? 'forge',
            'password' => $env['DB_PASSWORD'] ?? '',
        ];

        try {
            $shell = (new SshAccess($context))->open();
            $this->checkTools($shell, $engine);

            $cluster = $this->openPublicAccess($context, $target['cluster_id']);
            $connection = $cluster['data']['attributes']['connection'] ?? [];

            $destination = [
                'host' => (string) ($connection['hostname'] ?? ''),
                'port' => (string) ($connection['port'] ?? ($engine === 'pgsql' ? 5432 : 3306)),
                'database' => $target['schema'],
                'username' => (string) ($connection['username'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
            ];

            if ($destination['host'] === '' || $destination['username'] === '') {
                throw new MigrationException('Cloud didn\'t return connection details for the database cluster yet. Try again in a minute.');
            }

            $result = Spinner::run(
                fn () => $shell->run(DatabaseScripts::copy($engine, $source, $destination), timeout: 6 * 3600),
                "Copying {$source['database']} to Cloud..."
            );

            if (! $result->successful()) {
                throw new MigrationException('The database copy failed: '.self::tail($result->errorOutput() ?: $result->output()));
            }

            if (trim($result->errorOutput()) !== '') {
                $this->warn('The copy finished with warnings: '.self::tail($result->errorOutput()));
            }

            return $this->verify($context, $shell, $engine, $source, $destination);
        } finally {
            self::closePublicAccess($context);
            SshAccess::close($context);
        }
    }

    /**
     * Switch a Laravel MySQL cluster's public endpoint back off, if the
     * migration switched it on.
     */
    public static function closePublicAccess(MigrationContext $context): void
    {
        $record = $context->cleanup(self::CLEANUP);

        if (! $record) {
            return;
        }

        $cloud = $context->cloud();
        $config = $cloud->getDatabaseCluster($record['cluster_id'])['data']['attributes']['config'] ?? [];

        if ($config['is_public'] ?? false) {
            $cloud->updateDatabaseCluster($record['cluster_id'], ['config' => array_merge($config, ['is_public' => false])]);
        }

        $context->clearCleanup(self::CLEANUP);
    }

    /**
     * The Cloud cluster and database (schema) the provision step created.
     *
     * @return array{key: string, cluster_id: string, schema: string}
     */
    protected function cloudDatabase(MigrationContext $context): array
    {
        foreach ($context->cloudConfig()['databases'] ?? [] as $key => $database) {
            $environments = $database['environments'] ?? [];
            $schema = array_is_list($environments)
                ? (in_array(ConfigGenerator::ENVIRONMENT, $environments, true) ? ConfigGenerator::ENVIRONMENT : null)
                : ($environments[ConfigGenerator::ENVIRONMENT] ?? null);

            $clusterId = $context->state->get("resources.databases.{$key}.id");

            if ($schema !== null && $clusterId) {
                return ['key' => (string) $key, 'cluster_id' => $clusterId, 'schema' => (string) $schema];
            }
        }

        throw new MigrationException('There is no Cloud database attached to the production environment yet.', [
            'Check the databases section of config/cloud.php, then run: php artisan cloud:migrate-from-forge --step=provision',
        ]);
    }

    protected function checkTools(RemoteShell $shell, string $engine): void
    {
        $output = $shell->run(DatabaseScripts::tools($engine), timeout: 60)->output();

        preg_match_all('/missing:(\S+)/', $output, $matches);

        if ($matches[1] !== []) {
            throw new MigrationException('The Forge server is missing '.implode(', ', $matches[1]).'.', [
                $engine === 'pgsql'
                    ? 'Install it with: sudo apt-get install -y postgresql-client'
                    : 'Install it with: sudo apt-get install -y mysql-client',
            ]);
        }
    }

    /**
     * Laravel MySQL clusters are private unless their public endpoint is on.
     * Neon Postgres is always reachable over TLS.
     *
     * @return array<string, mixed> The cluster, once it's reachable
     */
    protected function openPublicAccess(MigrationContext $context, string $clusterId): array
    {
        $cloud = $context->cloud();
        $cluster = $cloud->getDatabaseCluster($clusterId);
        $attributes = $cluster['data']['attributes'] ?? [];

        if (($attributes['type'] ?? null) !== 'laravel_mysql' || ($attributes['config']['is_public'] ?? false)) {
            return $cluster;
        }

        // Record it first, so a crash still switches it back off next run.
        $context->registerCleanup(self::CLEANUP, ['cluster_id' => $clusterId]);

        $cloud->updateDatabaseCluster($clusterId, [
            'config' => array_merge($attributes['config'] ?? [], ['is_public' => true]),
        ]);

        return Spinner::run(function () use ($cloud, $clusterId) {
            for ($attempt = 1; $attempt <= static::$attempts; $attempt++) {
                Sleep::for(static::$pollSeconds)->seconds();

                $cluster = $cloud->getDatabaseCluster($clusterId);

                if (($cluster['data']['attributes']['status'] ?? null) === 'available') {
                    return $cluster;
                }
            }

            throw new MigrationException('Timed out waiting for the database\'s public endpoint to switch on.');
        }, 'Switching on the Cloud database\'s public endpoint for the copy...');
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    protected function verify(MigrationContext $context, RemoteShell $shell, string $engine, array $source, array $target): bool
    {
        $result = Spinner::run(
            fn () => $shell->run(DatabaseScripts::count($engine, $source, $target), timeout: 3600),
            'Comparing row counts...'
        );

        $comparison = DatabaseScripts::compareCounts($result->output());

        if ($comparison['mismatches'] === []) {
            $this->success("Database copied. All {$comparison['tables']} tables have the same number of rows on both sides.");
            $context->put('database.copied_at', now()->toIso8601String());

            return true;
        }

        $this->table(['Table', 'Forge rows', 'Cloud rows'], array_map(fn ($row) => array_values($row), $comparison['mismatches']));
        $this->warn('Some tables differ. If the site was taking writes during the copy that is expected; the cutover copies again with the site in maintenance mode.');

        $context->put('database.copied_at', now()->toIso8601String());

        return confirm('Carry on anyway?', default: true);
    }

    protected static function tail(string $output, int $lines = 10): string
    {
        return implode(PHP_EOL, array_slice(preg_split('/\r\n|\n/', trim($output)) ?: [], -$lines));
    }
}
