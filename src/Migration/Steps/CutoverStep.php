<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Moves traffic from Forge to Cloud.
 *
 * Each part is recorded as it finishes, so stopping while DNS propagates
 * and running the command again carries on where it left off without
 * copying the data twice.
 */
class CutoverStep extends Step
{
    /**
     * Seconds to wait between automatic domain checks.
     */
    public static int $waitSeconds = 30;

    public function __construct(
        protected DatabaseStep $database = new DatabaseStep,
        protected FilesStep $files = new FilesStep,
        protected DeployStep $deploy = new DeployStep,
    ) {}

    public function name(): string
    {
        return 'cutover';
    }

    public function title(): string
    {
        return 'Cut over';
    }

    public function explanation(): string
    {
        return 'Move your domain to Cloud. The Forge site goes into maintenance mode so nothing changes while the database is copied '
            .'one last time and new files are synced. Then your domains are added to Cloud and you update DNS. '
            .'Forge is never deleted: it stays in maintenance mode, ready to roll back to.';
    }

    public function handle(MigrationContext $context): bool
    {
        $inspection = $context->inspection();

        if (! $context->get('cutover.started')) {
            $this->explain(
                'Before you start:',
                '1. Lower the TTL on your DNS records (e.g. to 60 seconds) and wait for the old TTL to expire. That makes the switch, and any rollback, quick.',
                '2. Make sure the app works on its Cloud URL'.($context->get('deploy.url') ? ' ('.$context->get('deploy.url').')' : '').'.',
                '3. Pick a quiet time. The site shows a maintenance page from now until DNS points at Cloud.',
            );

            if (! confirm('Ready to put the Forge site into maintenance mode and start the cutover?', default: false)) {
                return false;
            }

            $context->put('cutover.started', now()->toIso8601String());
        }

        $serverId = (string) $context->get('forge.server_id');
        $siteId = (string) $context->get('forge.site_id');

        if (! $context->get('cutover.maintenance')) {
            $context->forge()->enableMaintenanceMode($serverId, $siteId);
            $context->put('cutover.maintenance', true);
            $this->success('The Forge site is in maintenance mode.');
        }

        if (! $context->get('cutover.database') && ($inspection['database']['engine'] ?? null) !== null) {
            if (! $this->database->copy($context)) {
                return false;
            }

            $context->put('cutover.database', true);
        }

        if (! $context->get('cutover.files')) {
            $this->files->sync($context);
            $context->put('cutover.files', true);
        }

        if (! $context->get('cutover.domains')) {
            $this->addDomains($context, $inspection['domains']);
            $context->put('cutover.domains', true);
        }

        if (! $context->get('cutover.verified')) {
            if (! $this->waitForDomains($context)) {
                return false;
            }

            $context->put('cutover.verified', true);
        }

        if (! $context->get('cutover.app_url')) {
            $this->updateAppUrl($context);
            $context->put('cutover.app_url', true);
        }

        $this->finish($context);

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $domains
     */
    protected function addDomains(MigrationContext $context, array $domains): void
    {
        $cloud = $context->cloud();
        $environmentId = $context->environmentId();

        $options = collect($domains)->mapWithKeys(fn (array $domain) => [$domain['name'] => "{$domain['name']} ({$domain['type']})"])->all();

        $chosen = $options === [] ? [] : multiselect(
            'Which domains should move to Cloud?',
            $options,
            default: array_keys($options),
        );

        foreach ($domains as $domain) {
            if (! in_array($domain['name'], $chosen, true)) {
                continue;
            }

            $existing = $cloud->findDomainByName($environmentId, $domain['name']);

            $id = $existing['id'] ?? $cloud->createDomain($environmentId, array_filter([
                'name' => $domain['name'],
                'www_redirect' => match ($domain['www_redirect_type'] ?? 'none') {
                    'from-www' => 'www_to_root',
                    'to-www' => 'root_to_www',
                    default => null,
                },
                'wildcard_enabled' => $domain['wildcard'] ?? false,
            ], fn ($value) => $value !== null))['data']['id'];

            $context->state->setDomainId($context->environmentName(), $domain['name'], $id);
            $context->save();
        }

        if ($chosen === []) {
            $this->warn('No domains chosen. The app stays on its Cloud URL.');
        }
    }

    /**
     * Show the DNS records and check until every domain is live.
     */
    protected function waitForDomains(MigrationContext $context): bool
    {
        $domainIds = $context->state->get('environments.'.$context->environmentName().'.domains', []);

        if ($domainIds === []) {
            return true;
        }

        $cloud = $context->cloud();
        $shownRecords = false;

        while (true) {
            $domains = [];

            foreach ($domainIds as $name => $domain) {
                $domains[$name] = $cloud->verifyDomain($domain['id'])['data']['attributes'] ?? [];
            }

            if (! $shownRecords) {
                $this->showDnsRecords($domains);
                $shownRecords = true;
            }

            $this->table(['Domain', 'Hostname', 'SSL', 'Origin'], collect($domains)->map(fn (array $domain, string $name) => [
                $name,
                (string) ($domain['hostname_status'] ?? '?'),
                (string) ($domain['ssl_status'] ?? '?'),
                (string) ($domain['origin_status'] ?? '?'),
            ])->values()->all());

            $pending = collect($domains)->reject(fn (array $domain) => ($domain['hostname_status'] ?? null) === 'verified'
                && ($domain['ssl_status'] ?? null) === 'verified'
                && ($domain['origin_status'] ?? null) === 'verified');

            if ($pending->isEmpty()) {
                $this->success('Every domain is verified and serving from Cloud.');

                return true;
            }

            if ($pending->contains(fn (array $domain) => in_array('failed', [$domain['hostname_status'] ?? null, $domain['ssl_status'] ?? null, $domain['origin_status'] ?? null], true))) {
                $this->warn('A check failed. Make sure the DNS records match the ones above exactly.');
            }

            $choice = select('DNS changes can take a while to spread. What now?', [
                'wait' => 'Wait '.static::$waitSeconds.' seconds and check again',
                'records' => 'Show the DNS records again',
                'later' => 'Stop here; run the command again later to carry on',
            ]);

            if ($choice === 'later') {
                $this->explain('The Forge site stays in maintenance mode. Run php artisan cloud:migrate-from-forge to carry on checking.');

                return false;
            }

            if ($choice === 'records') {
                $this->showDnsRecords($domains);

                continue;
            }

            Sleep::for(static::$waitSeconds)->seconds();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $domains
     */
    protected function showDnsRecords(array $domains): void
    {
        $rows = [];

        foreach ($domains as $name => $domain) {
            $records = $domain['dns_records'] ?? [];

            foreach ($records['ssl'] ?? [] as $record) {
                $rows[] = [$name, 'SSL', (string) $record['type'], (string) $record['name'], (string) $record['value']];
            }

            foreach (['pre_verification' => 'Ownership', 'origin' => 'Origin', 'origin_cname' => 'Origin (CNAME)', 'dcv' => 'Certificate'] as $key => $label) {
                if (! empty($records[$key])) {
                    $rows[] = [$name, $label, '', '', (string) $records[$key]];
                }
            }

            foreach (['www', 'wildcard'] as $variant) {
                foreach ($domain[$variant]['dns_records']['ssl'] ?? [] as $record) {
                    $rows[] = ["{$name} ({$variant})", 'SSL', (string) $record['type'], (string) $record['name'], (string) $record['value']];
                }
            }
        }

        $this->explain('Add these records at your DNS provider. Remove any old A or CNAME records for these names that point at the Forge server.');
        $this->table(['Domain', 'Purpose', 'Type', 'Name', 'Value'], $rows);
    }

    protected function updateAppUrl(MigrationContext $context): void
    {
        $primary = collect($context->inspection()['domains'])->firstWhere('type', 'primary')['name'] ?? null;
        $moved = array_keys($context->state->get('environments.'.$context->environmentName().'.domains', []));

        if (! $primary || ! in_array($primary, $moved, true)) {
            return;
        }

        $context->cloud()->setEnvironmentVariables($context->environmentId(), [
            ['key' => 'APP_URL', 'value' => "https://{$primary}"],
        ]);

        $this->success("APP_URL is now https://{$primary}.");

        if (confirm('Redeploy so the new APP_URL takes effect?')) {
            $this->deploy->deploy($context);
        }
    }

    protected function finish(MigrationContext $context): void
    {
        $inspection = $context->inspection();

        $this->success('The site is running on Laravel Cloud.');

        $this->explain(
            'Keep the Forge server running for a while. It is still in maintenance mode and nothing on it was deleted.',
            'To roll back:',
            '1. Point DNS back at the Forge server ('.$inspection['server']['ip_address'].').',
            '2. Take the Forge site out of maintenance mode: in Forge, or run "php artisan up" in '.$inspection['site']['path'].' on the server.',
            'Anything written to the Cloud database after cutover is not copied back to Forge.',
        );
    }
}
