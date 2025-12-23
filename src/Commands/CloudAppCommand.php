<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use NativePhp\LaravelCloudDeploy\CloudClient;

class CloudAppCommand extends Command
{
    protected $signature = 'cloud:apps
                            {application? : Show environments for a specific application (by name or ID)}';

    protected $description = 'List applications and their environments on Laravel Cloud';

    protected CloudClient $client;

    public function handle(): int
    {
        if (! $this->validateConfig()) {
            return self::FAILURE;
        }

        try {
            $this->client = new CloudClient(config('cloud.token'));

            $application = $this->argument('application');

            if ($application) {
                return $this->showApplicationDetails($application);
            }

            return $this->listApplications();
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

    protected function listApplications(): int
    {
        $applications = $this->client->listApplications();

        if (empty($applications['data'])) {
            $this->info('No applications found.');

            return self::SUCCESS;
        }

        $this->info('Applications');
        $this->line(str_repeat('─', 70));

        $rows = [];

        foreach ($applications['data'] as $app) {
            $attrs = $app['attributes'] ?? [];
            $repo = $attrs['repository']['full_name'] ?? 'N/A';

            $rows[] = [
                $attrs['name'] ?? 'N/A',
                $repo,
                $attrs['region'] ?? 'N/A',
            ];
        }

        $this->table(
            ['Name', 'Repository', 'Region'],
            $rows
        );

        $this->newLine();
        $this->line('<fg=gray>Use cloud:app NAME to see environments for a specific application.</>');

        return self::SUCCESS;
    }

    protected function showApplicationDetails(string $nameOrId): int
    {
        $app = $this->findApplication($nameOrId);

        if (! $app) {
            $this->error("Application '{$nameOrId}' not found.");

            return self::FAILURE;
        }

        $appId = $app['id'];
        $attrs = $app['attributes'] ?? [];
        $appName = $attrs['name'] ?? 'Unknown';

        $this->newLine();
        $this->info("Application: {$appName}");
        $this->line(str_repeat('─', 60));

        $repo = $attrs['repository']['full_name'] ?? null;
        $repoUrl = $repo ? "https://github.com/{$repo}" : 'N/A';

        $this->line('  <fg=gray>ID:</> '.$appId);
        $this->line('  <fg=gray>Repository:</> '.$repoUrl);
        $this->line('  <fg=gray>Region:</> '.($attrs['region'] ?? 'N/A'));

        // List environments
        $this->showEnvironments($appId);

        return self::SUCCESS;
    }

    protected function findApplication(string $nameOrId): ?array
    {
        $applications = $this->client->listApplications();

        foreach ($applications['data'] ?? [] as $app) {
            // Match by ID
            if ($app['id'] === $nameOrId) {
                return $app;
            }

            // Match by name (case-insensitive)
            $name = $app['attributes']['name'] ?? '';
            if (strcasecmp($name, $nameOrId) === 0) {
                return $app;
            }

            // Match by repository
            $repo = $app['attributes']['repository']['full_name'] ?? '';
            if (strcasecmp($repo, $nameOrId) === 0) {
                return $app;
            }
        }

        return null;
    }

    protected function showEnvironments(string $applicationId): void
    {
        $environments = $this->client->listEnvironments($applicationId);

        if (empty($environments['data'])) {
            $this->newLine();
            $this->line('  <fg=yellow>No environments found.</>');

            return;
        }

        $this->newLine();
        $this->info('  Environments');
        $this->line('  '.str_repeat('─', 56));

        foreach ($environments['data'] as $env) {
            $this->showEnvironmentSummary($env);
        }
    }

    protected function showEnvironmentSummary(array $env): void
    {
        $envId = $env['id'];
        $attrs = $env['attributes'] ?? [];
        $name = $attrs['name'] ?? 'Unknown';

        $this->newLine();
        $this->line("  <fg=cyan>{$name}</>");

        $this->line("    <fg=gray>ID:</> {$envId}");
        $this->line('    <fg=gray>PHP:</> '.($attrs['php_major_version'] ?? 'N/A'));

        // Vanity domain
        $vanityDomain = $attrs['vanity_domain'] ?? null;
        if ($vanityDomain) {
            $this->line("    <fg=gray>URL:</> https://{$vanityDomain}");
        }

        // Key settings
        $settings = [];
        if ($attrs['uses_push_to_deploy'] ?? false) {
            $settings[] = 'push-to-deploy';
        }
        if ($attrs['uses_octane'] ?? false) {
            $settings[] = 'octane';
        }
        if ($attrs['uses_hibernation'] ?? false) {
            $settings[] = 'hibernation';
        }

        if (! empty($settings)) {
            $this->line('    <fg=gray>Features:</> '.implode(', ', $settings));
        }

        // Show instances
        $this->showInstancesSummary($envId);
    }

    protected function showInstancesSummary(string $environmentId): void
    {
        $instances = $this->client->listInstances($environmentId);

        if (empty($instances['data'])) {
            return;
        }

        $this->line('    <fg=gray>Instances:</>');

        foreach ($instances['data'] as $instance) {
            $attrs = $instance['attributes'] ?? [];
            $name = $attrs['name'] ?? 'Unknown';
            $type = $attrs['type'] ?? 'N/A';
            $size = $attrs['size'] ?? 'N/A';
            $replicas = $attrs['replicas'] ?? 0;
            $scalingType = $attrs['scaling_type'] ?? 'none';

            $scaling = $scalingType === 'none'
                ? "{$replicas} replica".($replicas !== 1 ? 's' : '')
                : "{$scalingType} ({$attrs['min_replicas']}-{$attrs['max_replicas']})";

            $this->line("      • {$name} <fg=gray>({$type})</>");
            $this->line("        {$size}, {$scaling}");
        }
    }
}
