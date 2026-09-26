<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\Support\ForgeInspector;
use NativePhp\LaravelCloudDeploy\Migration\Support\Spinner;

/**
 * Reads the site's setup from Forge.
 */
class InspectStep extends Step
{
    public function name(): string
    {
        return 'inspect';
    }

    public function title(): string
    {
        return 'Read the site from Forge';
    }

    public function explanation(): string
    {
        return 'Read the site\'s settings, .env, deploy script, workers, scheduled jobs, database, domains and web server rules from Forge. '
            .'Only facts about the setup are saved to .laravel-cloud.json; secrets are re-read from Forge when they\'re needed.';
    }

    public function runsInDryRun(): bool
    {
        return true;
    }

    public function handle(MigrationContext $context): bool
    {
        $inspection = Spinner::run(
            fn () => (new ForgeInspector($context->forge()))->inspect(
                (string) $context->get('forge.server_id'),
                (string) $context->get('forge.site_id'),
                $context->projectPath,
            ),
            'Reading the site from Forge...'
        );

        $context->put('inspection', $inspection);

        $site = $inspection['site'];

        $this->table(['', ''], [
            ['Site', (string) $site['name']],
            ['Server', $inspection['server']['name'].' ('.$inspection['server']['ip_address'].')'],
            ['Repository', ($site['repository']['full_name'] ?? $site['repository']['url'] ?? 'none').' @ '.($site['repository']['branch'] ?? '?')],
            ['Database', (string) ($inspection['database']['engine'] ?? 'none')],
            ['Background processes', (string) count($inspection['processes'])],
            ['Scheduled jobs', (string) count($inspection['scheduled_jobs'])],
            ['Domains', implode(', ', array_column($inspection['domains'], 'name')) ?: 'none'],
        ]);

        // An existing Cloud app for the same repository proves GitHub is connected.
        $repository = $site['repository']['full_name'] ?? null;

        if ($repository && $context->cloud()->findApplicationByRepository($repository)) {
            $this->success("Cloud already has an application for {$repository}, so GitHub is connected.");
            $context->put('github.connected', 'proven');
        }

        return true;
    }
}
