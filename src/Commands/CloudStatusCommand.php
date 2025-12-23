<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use NativePhp\LaravelCloudDeploy\CloudClient;
use NativePhp\LaravelCloudDeploy\CloudState;

class CloudStatusCommand extends Command
{
    protected $signature = 'cloud:status
                            {environment? : The environment to check (defaults to all configured environments)}';

    protected $description = 'Check the live state of your Laravel Cloud environments';

    protected CloudClient $client;

    protected CloudState $state;

    public function handle(): int
    {
        if (! $this->validateConfig()) {
            return self::FAILURE;
        }

        try {
            $this->client = new CloudClient(config('cloud.token'));
            $this->state = new CloudState;

            $applicationId = $this->state->getApplicationId();

            if (! $applicationId) {
                $repository = config('cloud.application.repository');
                $app = $this->client->findApplicationByRepository($repository);

                if (! $app) {
                    $this->error('Application not found. Run cloud:deploy first to create it.');

                    return self::FAILURE;
                }

                $applicationId = $app['id'];
            }

            $environments = $this->getEnvironmentsToCheck();

            foreach ($environments as $envName => $envConfig) {
                $this->showEnvironmentStatus($envName, $applicationId);
            }

            return self::SUCCESS;
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

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getEnvironmentsToCheck(): array
    {
        $allEnvironments = config('cloud.environments', []);
        $requestedEnv = $this->argument('environment');

        if ($requestedEnv) {
            if (! isset($allEnvironments[$requestedEnv])) {
                throw new \InvalidArgumentException("Environment '{$requestedEnv}' is not configured.");
            }

            return [$requestedEnv => $allEnvironments[$requestedEnv]];
        }

        return $allEnvironments;
    }

    protected function showEnvironmentStatus(string $envName, string $applicationId): void
    {
        $this->newLine();
        $this->info("Environment: {$envName}");
        $this->line(str_repeat('─', 50));

        $environmentId = $this->state->getEnvironmentId($envName);

        if (! $environmentId) {
            $env = $this->client->findEnvironmentByName($applicationId, $envName);

            if (! $env) {
                $this->warn('  Not deployed yet.');

                return;
            }

            $environmentId = $env['id'];
        }

        $environment = $this->client->getEnvironment($environmentId);
        $attrs = $environment['data']['attributes'] ?? [];

        // Basic info
        $this->line("  <fg=gray>ID:</> {$environmentId}");
        $this->line('  <fg=gray>PHP:</> '.($attrs['php_major_version'] ?? 'N/A'));
        $this->line('  <fg=gray>Node:</> '.($attrs['node_version'] ?? 'N/A'));

        // Status indicators
        $vanityDomain = $attrs['vanity_domain'] ?? null;
        if ($vanityDomain) {
            $this->line("  <fg=gray>Vanity URL:</> https://{$vanityDomain}");
        }

        $this->line('  <fg=gray>Push to Deploy:</> '.($attrs['uses_push_to_deploy'] ?? false ? 'Yes' : 'No'));
        $this->line('  <fg=gray>Octane:</> '.($attrs['uses_octane'] ?? false ? 'Yes' : 'No'));
        $this->line('  <fg=gray>Web Server:</> '.($attrs['uses_web_server'] ?? false ? 'Yes' : 'No'));

        // Show instances
        $this->showInstances($environmentId);

        // Show recent deployment
        $this->showRecentDeployment($environmentId);

        // Show domains
        $this->showDomains($environmentId);
    }

    protected function showInstances(string $environmentId): void
    {
        $instances = $this->client->listInstances($environmentId);

        if (empty($instances['data'])) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Instances:</>');

        foreach ($instances['data'] as $instance) {
            $attrs = $instance['attributes'] ?? [];
            $name = $attrs['name'] ?? 'Unknown';
            $type = $attrs['type'] ?? 'N/A';
            $size = $attrs['size'] ?? 'N/A';
            $replicas = $attrs['replicas'] ?? 0;

            $this->line("    • {$name} ({$type})");
            $this->line("      <fg=gray>Size:</> {$size}");
            $this->line("      <fg=gray>Replicas:</> {$replicas}");
        }
    }

    protected function showRecentDeployment(string $environmentId): void
    {
        $deployments = $this->client->listDeployments($environmentId);

        if (empty($deployments['data'])) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Latest Deployment:</>');

        $latest = $deployments['data'][0] ?? null;

        if ($latest) {
            $attrs = $latest['attributes'] ?? [];
            $status = $attrs['status'] ?? 'unknown';
            $createdAt = $attrs['created_at'] ?? 'N/A';
            $commit = $attrs['commit_hash'] ?? 'N/A';

            $statusColor = match ($status) {
                'deployed', 'deployment.succeeded' => 'green',
                'failed', 'deployment.failed' => 'red',
                'deploying', 'building', 'pending' => 'yellow',
                default => 'gray',
            };

            $this->line("    <fg=gray>Status:</> <fg={$statusColor}>{$status}</>");
            $this->line('    <fg=gray>Commit:</> '.substr($commit, 0, 8));
            $this->line("    <fg=gray>Created:</> {$createdAt}");
        }
    }

    protected function showDomains(string $environmentId): void
    {
        $domains = $this->client->listDomains($environmentId);

        if (empty($domains['data'])) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Domains:</>');

        foreach ($domains['data'] as $domain) {
            $attrs = $domain['attributes'] ?? [];
            $name = $attrs['name'] ?? 'Unknown';
            $verified = $attrs['is_verified'] ?? false;
            $status = $verified ? '<fg=green>verified</>' : '<fg=yellow>pending</>';

            $this->line("    • {$name} ({$status})");
        }
    }
}
