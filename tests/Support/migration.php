<?php

declare(strict_types=1);

use NativePhp\LaravelCloudDeploy\CloudState;
use NativePhp\LaravelCloudDeploy\ForgeClient;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigRenderer;
use NativePhp\LaravelCloudDeploy\Migration\Support\ForgeInspector;
use NativePhp\LaravelCloudDeploy\Tests\Support\FakeApis;

function migrationState(string $project): CloudState
{
    return new CloudState($project.'/.laravel-cloud.json');
}

/**
 * Save progress as if the given steps had already run against the fake site,
 * with tokens configured and config/cloud.php written.
 *
 * @param  array<int, string>  $steps
 * @param  array<string, mixed>  $plan
 */
function prepareMigration(string $project, array $steps, array $plan = []): CloudState
{
    config(['cloud.token' => 'cloud-token', 'cloud.forge' => ['token' => 'forge-token', 'organization' => 'acme']]);

    FakeApis::fake();
    $inspection = (new ForgeInspector(new ForgeClient('forge-token', 'acme')))->inspect('101', '202', $project);
    $plan = array_merge(['app_name' => 'Shop', 'region' => 'eu-west-2', 'public_bucket' => false, 'private_bucket' => false], $plan);

    @mkdir($project.'/config');
    file_put_contents($project.'/config/cloud.php', ConfigRenderer::render(ConfigGenerator::generate($inspection, $plan)));

    $state = migrationState($project);
    $state->set('migration', [
        'forge' => ['organization' => 'acme', 'server_id' => '101', 'site_id' => '202'],
        'cloud' => ['organization_id' => 'org-1'],
        'source_control' => ['connected' => 'confirmed'],
        'inspection' => $inspection,
        'plan' => $plan,
        'steps' => array_fill_keys($steps, '2024-01-01T00:00:00+00:00'),
    ]);
    $state->save();

    return $state;
}
