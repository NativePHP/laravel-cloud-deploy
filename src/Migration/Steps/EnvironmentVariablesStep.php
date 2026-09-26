<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;
use NativePhp\LaravelCloudDeploy\Migration\Support\EnvFile;
use NativePhp\LaravelCloudDeploy\Migration\Support\EnvironmentVariableFilter;

use function Laravel\Prompts\confirm;

/**
 * Copies the Forge site's .env to the Cloud environment, minus the
 * variables Cloud injects for attached resources.
 */
class EnvironmentVariablesStep extends Step
{
    public function name(): string
    {
        return 'env';
    }

    public function title(): string
    {
        return 'Copy environment variables';
    }

    public function explanation(): string
    {
        return 'Copy the Forge .env to the Cloud environment. Variables for the database, cache and storage are left out because '
            .'Cloud injects its own, and a copied value would point the app back at Forge. APP_URL is set to the Cloud URL until cutover. '
            .'Only names are shown here, never values.';
    }

    public function handle(MigrationContext $context): bool
    {
        $environmentId = $context->environmentId();
        $config = $context->cloudConfig();
        $environment = $context->cloud()->getEnvironment($environmentId)['data']['attributes'] ?? [];
        $vanity = $environment['vanity_domain'] ?? null;

        $variables = EnvFile::parse($context->forge()->getEnvironmentFile(
            (string) $context->get('forge.server_id'),
            (string) $context->get('forge.site_id'),
        ));

        $result = EnvironmentVariableFilter::filter($variables, [
            'database' => self::attachesTo($config['databases'] ?? []),
            'cache' => self::attachesTo($config['caches'] ?? []),
            'bucket' => self::attachesTo($config['buckets'] ?? []),
            'app_url' => $vanity ? 'https://'.preg_replace('#^https?://#', '', $vanity) : null,
        ]);

        $rows = [];

        foreach (array_keys($result['keep']) as $key) {
            $rows[] = [$key, isset($result['replaced'][$key]) ? 'change' : 'copy', $result['replaced'][$key] ?? ''];
        }

        foreach ($result['drop'] as $key => $reason) {
            $rows[] = [$key, 'skip', $reason];
        }

        $this->table(['Variable', 'Action', 'Why'], $rows);

        if (! confirm(sprintf('Copy %d variables to the Cloud environment?', count($result['keep'])))) {
            return false;
        }

        $context->cloud()->setEnvironmentVariables($environmentId, array_map(
            fn (string $key, string $value) => ['key' => $key, 'value' => $value],
            array_keys($result['keep']),
            array_values($result['keep']),
        ));

        if ($result['nightwatch_token'] !== null) {
            $context->cloud()->updateEnvironment($environmentId, ['nightwatch_token' => $result['nightwatch_token']]);
        }

        $this->success('Environment variables copied. They take effect on the next deploy.');

        return true;
    }

    /**
     * Whether any resource in a config section is attached to production.
     *
     * @param  array<string, array<string, mixed>>  $resources
     */
    public static function attachesTo(array $resources): bool
    {
        foreach ($resources as $resource) {
            $environments = $resource['environments'] ?? [];

            if (in_array(ConfigGenerator::ENVIRONMENT, $environments, true) || isset($environments[ConfigGenerator::ENVIRONMENT])) {
                return true;
            }
        }

        return false;
    }
}
