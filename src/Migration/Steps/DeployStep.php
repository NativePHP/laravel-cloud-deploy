<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use Illuminate\Support\Facades\Http;
use NativePhp\LaravelCloudDeploy\Enums\DeploymentStatus;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;

use function Laravel\Prompts\confirm;

/**
 * Deploys to Cloud and checks the app answers on its Cloud URL.
 */
class DeployStep extends Step
{
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
        return 'Deploy the app to Cloud and request its free *.laravel.cloud URL to check it responds. '
            .'Your domain still points at Forge, so visitors aren\'t affected.';
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
        $context->state->setLastDeploymentId(ConfigGenerator::ENVIRONMENT, $deploymentId);
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
