<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use NativePhp\LaravelCloudDeploy\CloudClient;

class CloudDatabasesCommand extends Command
{
    protected $signature = 'cloud:databases
                            {--cluster= : Show details for a specific cluster by name}';

    protected $description = 'List all database clusters in your Laravel Cloud account';

    protected CloudClient $client;

    public function handle(): int
    {
        if (! $this->validateConfig()) {
            return self::FAILURE;
        }

        try {
            $this->client = new CloudClient(config('cloud.token'));

            $clusterName = $this->option('cluster');

            if ($clusterName) {
                return $this->showClusterDetails($clusterName);
            }

            return $this->listAllClusters();
        } catch (RequestException $e) {
            $this->error('API Error: '.$e->response->json('message', $e->getMessage()));

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function validateConfig(): bool
    {
        $token = config('cloud.token');

        if (empty($token)) {
            $this->error('LARAVEL_CLOUD_TOKEN is not set in your .env file.');

            return false;
        }

        return true;
    }

    protected function listAllClusters(): int
    {
        $clusters = $this->client->listDatabaseClusters();

        if (empty($clusters['data'])) {
            $this->info('No database clusters found.');

            return self::SUCCESS;
        }

        $this->info('Database Clusters');
        $this->line(str_repeat('─', 60));

        $rows = [];

        foreach ($clusters['data'] as $cluster) {
            $attrs = $cluster['attributes'] ?? [];

            $rows[] = [
                $attrs['name'] ?? 'N/A',
                $this->formatType($attrs['type'] ?? 'N/A'),
                $attrs['region'] ?? 'N/A',
                $this->formatStatus($attrs['status'] ?? 'unknown'),
            ];
        }

        $this->table(
            ['Name', 'Type', 'Region', 'Status'],
            $rows
        );

        $this->newLine();
        $this->line('<fg=gray>Use --cluster=NAME to see details for a specific cluster.</>');

        return self::SUCCESS;
    }

    protected function showClusterDetails(string $name): int
    {
        $cluster = $this->client->findDatabaseClusterByName($name);

        if (! $cluster) {
            $this->error("Database cluster '{$name}' not found.");

            return self::FAILURE;
        }

        $clusterId = $cluster['id'];

        // Fetch with schemas included
        $response = $this->client->getDatabaseClusterWithSchemas($clusterId);
        $attrs = $response['data']['attributes'] ?? [];

        $this->newLine();
        $this->info("Database Cluster: {$name}");
        $this->line(str_repeat('─', 50));

        $this->line("  <fg=gray>ID:</> {$clusterId}");
        $this->line('  <fg=gray>Type:</> '.$this->formatType($attrs['type'] ?? 'N/A'));
        $this->line('  <fg=gray>Region:</> '.($attrs['region'] ?? 'N/A'));
        $this->line('  <fg=gray>Status:</> '.$this->formatStatus($attrs['status'] ?? 'unknown'));

        // Show configuration
        $config = $attrs['config'] ?? [];
        if (! empty($config)) {
            $this->newLine();
            $this->line('  <fg=cyan>Configuration:</>');

            foreach ($config as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'Yes' : 'No') : $value;
                $this->line("    <fg=gray>{$key}:</> {$displayValue}");
            }
        }

        // Show connection details if available
        $hostname = $attrs['hostname'] ?? null;
        if ($hostname) {
            $this->newLine();
            $this->line('  <fg=cyan>Connection:</>');
            $this->line("    <fg=gray>Host:</> {$hostname}");
            $this->line('    <fg=gray>Port:</> '.($attrs['port'] ?? 'N/A'));
        }

        // Show schemas from included resources
        $this->showSchemas($response['included'] ?? []);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $included
     */
    protected function showSchemas(array $included): void
    {
        // Filter for schema resources from the JSON:API included array
        $schemas = array_filter($included, fn ($item) => ($item['type'] ?? '') === 'databaseSchemas');

        if (empty($schemas)) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Schemas:</>');

        foreach ($schemas as $schema) {
            $attrs = $schema['attributes'] ?? [];
            $schemaName = $attrs['name'] ?? 'Unknown';

            $this->line("    • {$schemaName}");
        }
    }

    protected function formatType(string $type): string
    {
        return match ($type) {
            'laravel_mysql_8' => 'Laravel MySQL 8',
            'aws_rds_mysql_8' => 'AWS RDS MySQL 8',
            'neon_serverless_postgres_18' => 'Neon Postgres 18',
            'neon_serverless_postgres_17' => 'Neon Postgres 17',
            'neon_serverless_postgres_16' => 'Neon Postgres 16',
            default => $type,
        };
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'active', 'available' => "<fg=green>{$status}</>",
            'creating', 'modifying', 'pending' => "<fg=yellow>{$status}</>",
            'failed', 'error' => "<fg=red>{$status}</>",
            default => $status,
        };
    }
}
