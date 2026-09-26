<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;

use function Laravel\Prompts\select;

/**
 * Chooses the Forge server and site to migrate.
 */
class PickSiteStep extends Step
{
    public function name(): string
    {
        return 'site';
    }

    public function title(): string
    {
        return 'Choose the Forge site';
    }

    public function explanation(): string
    {
        return 'Pick the server and site to move. Nothing on Forge is changed by choosing it.';
    }

    public function runsInDryRun(): bool
    {
        return true;
    }

    public function handle(MigrationContext $context): bool
    {
        $forge = $context->forge();

        $servers = collect($forge->listServers())->mapWithKeys(fn (array $server) => [
            (string) $server['id'] => sprintf('%s (%s)', $server['attributes']['name'] ?? $server['id'], $server['attributes']['ip_address'] ?? 'no IP'),
        ])->all();

        if ($servers === []) {
            throw new MigrationException('There are no servers in this Forge organization.');
        }

        $serverId = (string) select('Which server is the site on?', $servers, default: $this->savedChoice($servers, $context->get('forge.server_id')));

        $sites = collect($forge->listSites($serverId))->mapWithKeys(fn (array $site) => [
            (string) $site['id'] => sprintf(
                '%s (%s)',
                $site['attributes']['name'] ?? $site['id'],
                $site['attributes']['repository']['url'] ?? 'no repository'
            ),
        ])->all();

        if ($sites === []) {
            throw new MigrationException('That server has no sites.');
        }

        $siteId = (string) select('Which site do you want to move to Laravel Cloud?', $sites, default: $this->savedChoice($sites, $context->get('forge.site_id')));

        // A different site invalidates everything learned about the old one.
        if ($context->get('forge.site_id') !== null && $context->get('forge.site_id') !== $siteId) {
            $context->forget('inspection');
            $context->forget('plan');
            $context->put('steps', array_intersect_key($context->get('steps', []), array_flip(['setup'])));
        }

        $context->put('forge.server_id', $serverId);
        $context->put('forge.site_id', $siteId);

        return true;
    }

    /**
     * @param  array<int|string, string>  $options
     */
    protected function savedChoice(array $options, ?string $saved): int|string|null
    {
        foreach (array_keys($options) as $key) {
            if ((string) $key === $saved) {
                return $key;
            }
        }

        return null;
    }
}
