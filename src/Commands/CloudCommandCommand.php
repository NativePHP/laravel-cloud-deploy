<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use NativePhp\LaravelCloudDeploy\CloudClient;
use NativePhp\LaravelCloudDeploy\Enums\CommandStatus;

class CloudCommandCommand extends Command
{
    protected $signature = 'cloud:command
                            {environment : The environment name}
                            {--app= : The application name or ID (defaults to configured app)}
                            {--run= : Run a command on the environment}
                            {--status= : Check the status of a command by ID}
                            {--wait : Wait for the command to complete (use with --run)}';

    protected $description = 'Run commands on Laravel Cloud environments and check their status';

    protected CloudClient $client;

    public function handle(): int
    {
        if (! $this->validateConfig()) {
            return self::FAILURE;
        }

        try {
            $this->client = new CloudClient(config('cloud.token'));

            $envName = $this->argument('environment');
            $applicationId = $this->resolveApplicationId($this->option('app'));

            if (! $applicationId) {
                $appOption = $this->option('app');
                if ($appOption) {
                    $this->error("Application '{$appOption}' not found.");
                } else {
                    $this->error('Could not determine application. Set LARAVEL_CLOUD_REPOSITORY in .env or use --app=');
                }

                return self::FAILURE;
            }

            $environmentId = $this->resolveEnvironmentId($applicationId, $envName);

            if (! $environmentId) {
                $this->error("Environment '{$envName}' not found.");

                return self::FAILURE;
            }

            if ($this->option('status')) {
                return $this->showCommandStatus($this->option('status'));
            }

            if ($this->option('run')) {
                return $this->executeCloudCommand($environmentId, $this->option('run'));
            }

            // Default to listing commands
            return $this->listCommands($environmentId);
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

    protected function resolveApplicationId(?string $nameOrId): ?string
    {
        // If no app specified, try to find by configured repository
        if (! $nameOrId) {
            $repository = config('cloud.application.repository');

            if (! $repository) {
                return null;
            }

            $app = $this->client->findApplicationByRepository($repository);

            return $app['id'] ?? null;
        }

        // Otherwise search by name or ID
        $applications = $this->client->listApplications();

        foreach ($applications['data'] ?? [] as $app) {
            // Match by ID
            if ($app['id'] === $nameOrId) {
                return $app['id'];
            }

            // Match by name (case-insensitive)
            $name = $app['attributes']['name'] ?? '';
            if (strcasecmp($name, $nameOrId) === 0) {
                return $app['id'];
            }
        }

        return null;
    }

    protected function resolveEnvironmentId(string $applicationId, string $envName): ?string
    {
        $env = $this->client->findEnvironmentByName($applicationId, $envName);

        return $env['id'] ?? null;
    }

    protected function executeCloudCommand(string $environmentId, string $command): int
    {
        $this->info("Running command: {$command}");

        $response = $this->client->runCommand($environmentId, $command);
        $commandId = $response['data']['id'] ?? null;

        if (! $commandId) {
            $this->error('Failed to get command ID from response.');

            return self::FAILURE;
        }

        $this->line("  <fg=gray>Command ID:</> {$commandId}");

        if ($this->option('wait')) {
            return $this->waitForCommand($commandId);
        }

        $this->newLine();
        $this->line('Command submitted. Use --status='.$commandId.' to check progress.');

        return self::SUCCESS;
    }

    protected function waitForCommand(string $commandId): int
    {
        $this->line('  Waiting for command to complete...');

        $maxAttempts = 120; // 10 minutes with 5-second intervals
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $status = $this->client->getCommandStatus($commandId);

            if ($status->isTerminal()) {
                $this->newLine();
                $command = $this->client->getCommand($commandId);
                $this->showCommandDetails($command['data']);

                return $status->isSuccessful() ? self::SUCCESS : self::FAILURE;
            }

            $attempt++;
            sleep(5);
        }

        $this->error('Command timed out after 10 minutes.');

        return self::FAILURE;
    }

    protected function showCommandStatus(string $commandId): int
    {
        $command = $this->client->getCommand($commandId);

        if (empty($command['data'])) {
            $this->error("Command '{$commandId}' not found.");

            return self::FAILURE;
        }

        $this->showCommandDetails($command['data']);

        return self::SUCCESS;
    }

    protected function showCommandDetails(array $command): void
    {
        $attrs = $command['attributes'] ?? [];
        $statusValue = $attrs['status'] ?? 'pending';
        $status = CommandStatus::tryFrom($statusValue);
        $statusColor = $status?->color() ?? 'gray';

        $this->newLine();
        $this->info('Command Details');
        $this->line(str_repeat('─', 50));

        $this->line('  <fg=gray>ID:</> '.$command['id']);
        $this->line('  <fg=gray>Command:</> '.($attrs['command'] ?? 'N/A'));
        $this->line("  <fg=gray>Status:</> <fg={$statusColor}>{$statusValue}</>");
        $this->line('  <fg=gray>Created:</> '.($attrs['created_at'] ?? 'N/A'));

        if (isset($attrs['finished_at'])) {
            $this->line('  <fg=gray>Finished:</> '.$attrs['finished_at']);
        }

        if (isset($attrs['exit_code'])) {
            $this->line('  <fg=gray>Exit Code:</> '.$attrs['exit_code']);
        }

        if (isset($attrs['failure_reason']) && $attrs['failure_reason']) {
            $this->line('  <fg=red>Failure Reason:</> '.$attrs['failure_reason']);
        }

        $output = $attrs['output'] ?? null;
        if ($output) {
            $this->newLine();
            $this->line('  <fg=cyan>Output:</>');
            $this->line($this->indentOutput($output));
        }
    }

    protected function listCommands(string $environmentId): int
    {
        $commands = $this->client->listCommands($environmentId);

        if (empty($commands['data'])) {
            $this->info('No commands found for this environment.');

            return self::SUCCESS;
        }

        $this->info('Recent Commands');
        $this->line(str_repeat('─', 80));

        $rows = [];

        foreach (array_slice($commands['data'], 0, 10) as $command) {
            $attrs = $command['attributes'] ?? [];
            $status = $attrs['status'] ?? 'unknown';

            $rows[] = [
                $command['id'],
                $this->truncate($attrs['command'] ?? 'N/A', 40),
                $this->formatStatus($status),
                $attrs['created_at'] ?? 'N/A',
            ];
        }

        $this->table(
            ['ID', 'Command', 'Status', 'Created'],
            $rows
        );

        return self::SUCCESS;
    }

    protected function formatStatus(string $statusValue): string
    {
        $status = CommandStatus::tryFrom($statusValue);
        $color = $status?->color() ?? 'gray';

        return "<fg={$color}>{$statusValue}</>";
    }

    protected function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3).'...';
    }

    protected function indentOutput(string $output): string
    {
        $lines = explode("\n", $output);

        return implode("\n", array_map(fn ($line) => '    '.$line, $lines));
    }
}
