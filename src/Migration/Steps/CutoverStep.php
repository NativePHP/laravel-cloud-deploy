<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\Support\Dns;

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
        return 'Move your domain to Cloud. Your current DNS records are saved for rollback, then you choose between a short maintenance '
            .'window and a zero-downtime switch. The database is copied one last time, new files are synced, your domains are added to Cloud '
            .'and you update DNS. Forge is never deleted, so you can roll back to it.';
    }

    public function handle(MigrationContext $context): bool
    {
        $inspection = $context->inspection();

        if (! $context->get('cutover.started')) {
            if (! $this->prepare($context, $inspection)) {
                return false;
            }
        }

        $serverId = (string) $context->get('forge.server_id');
        $siteId = (string) $context->get('forge.site_id');

        if ($this->usesMaintenanceMode($context) && ! $context->get('cutover.maintenance')) {
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
            if (! $this->usesMaintenanceMode($context)) {
                $this->warn('Forge is still live. Anything written there from now on, until DNS reaches Cloud, won\'t be in the Cloud database.');
            }

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

    /**
     * Save the current DNS, remind about TTL, and choose how to cut over.
     *
     * @param  array<string, mixed>  $inspection
     */
    protected function prepare(MigrationContext $context, array $inspection): bool
    {
        // Record DNS as it is now, before anything changes, for rollback.
        $records = Dns::snapshot(array_column($inspection['domains'], 'name'));
        $context->put('cutover.previous_dns', $records);

        if ($records !== []) {
            $this->explain('Your domain\'s DNS right now. It\'s saved in .laravel-cloud.json in case you need to roll back:');
            $this->table(['Name', 'Type', 'Value', 'TTL'], array_map(fn (array $record) => [
                $record['name'], $record['type'], $record['value'], (string) ($record['ttl'] ?? ''),
            ], $records));
        }

        $advised = Dns::ttlAdvisedAgo($context);

        if ($advised === null) {
            Dns::adviseTtl($context);
            $this->warn('If you haven\'t lowered your TTL yet, consider stopping here and coming back tomorrow.');
        } elseif ($advised->diffInHours(now()) < 24) {
            $this->warn('You were first reminded to lower your DNS TTL '.$advised->diffForHumans().'. If you lowered it since then, caches may still hold the old TTL, so the switch could take a while to reach everyone.');
        } else {
            $this->success('You were reminded to lower your DNS TTL '.$advised->diffForHumans().', so a lowered TTL should have taken effect.');
        }

        $this->explain(
            'There are two ways to cut over:',
            'Maintenance mode: the Forge site shows a maintenance page while the database is copied a last time and until DNS points at Cloud. '
                .'Visitors see a short outage, but no writes are lost.',
            'Zero downtime: Forge stays live while the last copy runs and DNS switches over. Nobody sees an outage, but anything written to Forge '
                .'after the last copy (orders, sign-ups, uploads) won\'t reach Cloud, and Forge\'s scheduler and workers keep running until you stop them.',
        );

        $mode = select('How do you want to cut over?', [
            'maintenance' => 'Maintenance mode (short outage, no lost writes)',
            'live' => 'Zero downtime (Forge stays live, recent writes on Forge may be lost)',
        ], default: 'maintenance');

        $question = $mode === 'maintenance'
            ? 'Ready to put the Forge site into maintenance mode and start the cutover?'
            : 'Ready to start the cutover? Forge stays live, and writes to it after the last copy won\'t reach Cloud.';

        if (! confirm($question, default: false)) {
            return false;
        }

        $context->put('cutover.mode', $mode);
        $context->put('cutover.started', now()->toIso8601String());

        return true;
    }

    protected function usesMaintenanceMode(MigrationContext $context): bool
    {
        return $context->get('cutover.mode', 'maintenance') === 'maintenance';
    }

    protected function finish(MigrationContext $context): void
    {
        $inspection = $context->inspection();
        $config = $context->cloudConfig()['environments'][$context->environmentName()] ?? [];

        $this->success('The site is running on Laravel Cloud.');

        $rollback = ['To roll back:'];
        $previous = $context->get('cutover.previous_dns', []);

        if ($previous !== []) {
            $rollback[] = '1. Put these DNS records back at your DNS provider:';

            foreach ($previous as $record) {
                $rollback[] = "   {$record['name']} {$record['type']} {$record['value']}";
            }
        } else {
            $rollback[] = '1. Point DNS back at the Forge server ('.$inspection['server']['ip_address'].').';
        }

        if ($this->usesMaintenanceMode($context)) {
            $rollback[] = '2. Take the Forge site out of maintenance mode: in Forge, or run "php artisan up" in '.$inspection['site']['path'].' on the server.';
        } else {
            $rollback[] = '2. The Forge site was never paused, so it will serve traffic again as soon as DNS points back at it.';
        }

        $rollback[] = 'Anything written to Cloud after the cutover isn\'t copied back to Forge.';

        $this->explain(...$rollback);

        $tips = [
            'Now that you\'re on Cloud:',
            '- Set autoscaling: pick minimum and maximum replicas for the App cluster that match your traffic.',
            '- For staging or quiet environments, turn on scale to zero (hibernation) so you pay nothing while they\'re idle.',
        ];

        if (! ($config['octane'] ?? false)) {
            $tips[] = '- Try Octane: it\'s a toggle in the environment settings (or set octane to true in config/cloud.php) and usually makes requests faster.';
        }

        $tips[] = '- Watch Cloud\'s metrics for CPU, memory and requests and compare them with what Forge showed.';

        $this->explain(...$tips);

        $this->explain(...[
            'Things still switched on:',
            '- Forge: the site is '.($this->usesMaintenanceMode($context) ? 'in maintenance mode' : 'still live').'. Its scheduled jobs and background processes are still running, so stop them in Forge to avoid jobs running twice. Keep the server until you\'re sure, then archive it.',
            '- Put your DNS TTL back up once things have settled.',
            '- The temporary SSH key is removed and the Cloud database\'s public endpoint was switched off after each copy.',
            '- If you made API tokens just for this, revoke them in Forge and Cloud.',
        ]);
    }
}
