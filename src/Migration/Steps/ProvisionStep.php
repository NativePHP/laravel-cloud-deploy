<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;

use function Laravel\Prompts\confirm;

/**
 * Creates the Cloud application, environment and resources by running
 * cloud:deploy --skip-deploy against the generated config.
 */
class ProvisionStep extends Step
{
    public function name(): string
    {
        return 'provision';
    }

    public function title(): string
    {
        return 'Create the app in Laravel Cloud';
    }

    public function explanation(): string
    {
        return 'Run cloud:deploy --skip-deploy with config/cloud.php. It creates the application from your GitHub repository, '
            .'the production environment, the app instance with its workers, and the database, cache and buckets, then attaches them. '
            .'Nothing is deployed yet and nothing on Forge changes. Running it again reuses what already exists.';
    }

    public function handle(MigrationContext $context): bool
    {
        if (! confirm('Create these resources in Laravel Cloud now? (They are billed by Cloud.)')) {
            return false;
        }

        while (true) {
            $this->loadConfig($context);

            // cloud:deploy reads and writes the same state file, so save first and reload after.
            $context->save();
            $exitCode = $context->command->call('cloud:deploy', [
                'environment' => ConfigGenerator::ENVIRONMENT,
                '--skip-deploy' => true,
                '--force' => true,
            ]);
            $context->state->load();

            if ($exitCode === 0) {
                $context->put('github.connected', 'proven');
                $this->success('The Cloud application and its resources are ready.');

                return true;
            }

            if ($context->state->getApplicationId() !== null) {
                throw new MigrationException('cloud:deploy stopped with an error (shown above).', [
                    'Fix it (often a size or name in config/cloud.php), then run: php artisan cloud:migrate-from-forge --step=provision',
                ]);
            }

            // Creating the application is what proves GitHub is connected.
            $this->fail('Cloud couldn\'t create the application. The usual cause is GitHub not being connected, or the Laravel Cloud GitHub app not having access to the repository.');
            $this->explain(...PreflightStep::CLOUD_SOURCE_CONTROL_HELP);

            if (! confirm('Try creating the application again?')) {
                return false;
            }
        }
    }

    /**
     * Point the running app's config at the freshly written config/cloud.php,
     * keeping the token and state file this run already uses.
     */
    protected function loadConfig(MigrationContext $context): void
    {
        $token = config('cloud.token');
        $statePath = config('cloud.state_path');

        config(['cloud' => array_merge($context->cloudConfig(), array_filter([
            'token' => $token,
            'state_path' => $statePath,
        ]))]);
    }
}
