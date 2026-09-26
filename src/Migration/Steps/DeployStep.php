<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\Enums\CommandStatus;
use NativePhp\LaravelCloudDeploy\Enums\DeploymentStatus;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;

use function Laravel\Prompts\confirm;

/**
 * Deploys to Cloud and checks the app answers on its Cloud URL.
 */
class DeployStep extends Step
{
    /**
     * How many times to check a queue:monitor command before giving up.
     */
    public static int $commandAttempts = 36;

    public function name(): string
    {
        return 'deploy';
    }

    public function title(): string
    {
        return 'Deploy and check';
    }

    public function explanation(): string
    {
        return 'Deploy the app to Cloud, request its free *.laravel.cloud URL to check it responds, and check the queues are ready '
            .'to process jobs. Your domain still points at Forge, so visitors aren\'t affected.';
    }

    public function handle(MigrationContext $context): bool
    {
        if (! confirm('Deploy to Cloud now?')) {
            return false;
        }

        return $this->deploy($context);
    }

    /**
     * Deploy and report. Used by this step and by cutover.
     */
    public function deploy(MigrationContext $context): bool
    {
        $cloud = $context->cloud();
        $environmentId = $context->environmentId();

        $deploymentId = $cloud->initiateDeployment($environmentId)['data']['id'];
        $context->state->setLastDeploymentId($context->environmentName(), $deploymentId);
        $context->save();

        $context->command->line("  Deployment {$deploymentId} started. Waiting for it to finish (this usually takes a few minutes)...");

        $deployment = $cloud->waitForDeployment($deploymentId, timeoutSeconds: 1800, onStatusChange: function (string $status) use ($context) {
            $context->command->line("  Status: {$status}");
        });

        $attributes = $deployment['data']['attributes'] ?? [];

        if (! DeploymentStatus::tryFrom($attributes['status'] ?? '')?->isSuccessful()) {
            $this->fail('The deployment failed: '.($attributes['failure_reason'] ?? $attributes['status'] ?? 'unknown reason'));
            $this->showLogs($context, $deploymentId);

            throw new MigrationException('Fix the problem above, push it, then run: php artisan cloud:migrate-from-forge --step=deploy', [
                'Build failures are usually a missing PHP extension, a build command that needs a variable, or private Composer packages.',
            ]);
        }

        $this->success('Deployed.');
        $this->checkUrl($context);
        $this->checkQueues($context);

        return true;
    }

    /**
     * Print the output of the build and deploy steps that didn't succeed.
     */
    protected function showLogs(MigrationContext $context, string $deploymentId): void
    {
        try {
            $logs = $context->cloud()->getDeploymentLogs($deploymentId)['data'] ?? [];
        } catch (\Throwable) {
            return;
        }

        foreach (['build', 'deploy'] as $phase) {
            foreach ($logs[$phase]['steps'] ?? [] as $step) {
                if (in_array($step['status'] ?? '', ['success', 'succeeded', 'skipped'], true)) {
                    continue;
                }

                $context->command->line("  [{$phase}] {$step['description']} ({$step['status']})");

                $output = array_slice(preg_split('/\r\n|\n/', trim((string) ($step['output'] ?? ''))) ?: [], -30);

                foreach ($output as $line) {
                    $context->command->line('    '.$line);
                }
            }
        }
    }

    /**
     * Check background jobs can run, without dispatching anything.
     *
     * Managed queues report their status and failed jobs through the API.
     * Worker processes don't, so for those queue:monitor is run in the
     * environment, which only reads the queue sizes.
     */
    protected function checkQueues(MigrationContext $context): void
    {
        $cloud = $context->cloud();
        $environmentId = $context->environmentId();
        $rows = [];
        $healthy = true;

        foreach ($cloud->all("/environments/{$environmentId}/instances") as $instance) {
            $attributes = $instance['attributes'] ?? [];

            if (($attributes['type'] ?? null) !== 'managed_queue') {
                continue;
            }

            $failed = $cloud->listFailedJobs((string) $instance['id']);
            $failedCount = (int) ($failed['meta']['total'] ?? count($failed['data'] ?? []));
            $status = (string) ($attributes['queue_status'] ?? 'unknown');
            $paused = (bool) ($attributes['paused'] ?? false);

            $healthy = $healthy && $status === 'available' && ! $paused && $failedCount === 0;
            $rows[] = [(string) $attributes['name'], $status, $paused ? 'yes' : 'no', (string) $failedCount];
        }

        if ($rows !== []) {
            $this->table(['Managed queue', 'Status', 'Paused', 'Failed jobs'], $rows);

            $healthy
                ? $this->success('Managed queues are available and have no failed jobs.')
                : $this->warn('A managed queue isn\'t ready, is paused or has failed jobs. Check the Queues dashboard under the environment\'s Monitoring tab.');
        }

        $workerQueues = $this->workerQueues($context);

        if ($workerQueues === []) {
            return;
        }

        $command = 'php artisan queue:monitor '.implode(',', $workerQueues);
        $context->command->line("  Checking worker queues with: {$command}");

        $id = $cloud->runCommand($environmentId, $command)['data']['id'] ?? null;

        for ($attempt = 0; $id !== null && $attempt < static::$commandAttempts; $attempt++) {
            $result = $cloud->getCommand($id)['data']['attributes'] ?? [];
            $status = CommandStatus::tryFrom($result['status'] ?? '');

            if ($status?->isTerminal()) {
                $context->command->line(trim((string) ($result['output'] ?? '')));

                $status->isSuccessful()
                    ? $this->success('Worker queues answered. If the sizes keep growing, check the worker processes in Cloud.')
                    : $this->warn('queue:monitor failed. Check the worker processes and the environment\'s logs in Cloud.');

                return;
            }

            Sleep::for(5)->seconds();
        }

        $this->warn('queue:monitor didn\'t finish in time. Check the worker processes in Cloud.');
    }

    /**
     * "connection:queue" for every queue a worker process on the app handles.
     *
     * @return array<int, string>
     */
    protected function workerQueues(MigrationContext $context): array
    {
        $queues = [];
        $instances = $context->cloudConfig()['environments'][$context->environmentName()]['instances'] ?? [];

        foreach ($instances as $instance) {
            foreach ($instance['processes'] ?? [] as $process) {
                if (($process['type'] ?? null) !== 'worker') {
                    continue;
                }

                foreach ((array) ($process['queue']['queues'] ?? ['default']) as $queue) {
                    $queues[] = ($process['queue']['connection'] ?? 'database').':'.$queue;
                }
            }
        }

        return array_values(array_unique($queues));
    }

    protected function checkUrl(MigrationContext $context): void
    {
        $vanity = $context->cloud()->getEnvironment($context->environmentId())['data']['attributes']['vanity_domain'] ?? null;

        if (! $vanity) {
            return;
        }

        $url = 'https://'.preg_replace('#^https?://#', '', $vanity);

        try {
            $status = Http::timeout(30)->withoutRedirecting()->get($url)->status();
        } catch (\Throwable $e) {
            $this->warn("Couldn't reach {$url}: {$e->getMessage()}");

            return;
        }

        $context->put('deploy.url', $url);

        if ($status >= 500) {
            $this->warn("{$url} answered with HTTP {$status}. Check the environment's logs in Cloud before cutting over.");

            return;
        }

        $this->success("{$url} answered with HTTP {$status}. Click around the app there before cutting over.");
    }
}
