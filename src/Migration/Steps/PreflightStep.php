<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Process;
use NativePhp\LaravelCloudDeploy\CloudClient;
use NativePhp\LaravelCloudDeploy\ForgeClient;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Support\EnvFile;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

/**
 * Makes sure both API tokens work, GitHub is connected to Cloud and the
 * local tools the data steps need are installed. Nothing else runs until
 * this passes.
 */
class PreflightStep extends Step
{
    public const CLOUD_SOURCE_CONTROL_HELP = [
        'In Laravel Cloud, open Account settings > Source control and connect GitHub.',
        'GitHub will ask you to install the Laravel Cloud app. Give it access to the repository you deploy from:',
        'https://github.com/apps/laravel-cloud-app/installations/select_target',
    ];

    public function name(): string
    {
        return 'setup';
    }

    public function title(): string
    {
        return 'Check API access';
    }

    public function explanation(): string
    {
        return 'First, check that this tool can talk to Forge and Laravel Cloud, that GitHub is connected to Cloud, and that SSH is available locally for copying data.';
    }

    public function runsInDryRun(): bool
    {
        return true;
    }

    public function handle(MigrationContext $context): bool
    {
        $this->connectForge($context);
        $this->connectCloud($context);
        $this->checkGitHub($context);
        $this->checkLocalTools($context);

        return true;
    }

    protected function connectForge(MigrationContext $context): void
    {
        $token = (string) (config('cloud.forge.token') ?: env('FORGE_API_TOKEN'));
        $fromEnvironment = $token !== '';

        if (! $fromEnvironment) {
            $this->explain(...[
                'FORGE_API_TOKEN isn\'t set. Create a token at '.ForgeClient::TOKEN_URL,
                'Give it these scopes:',
                ...array_map(fn ($scope, $reason) => "  {$scope}: {$reason}", array_keys(ForgeClient::SCOPES), ForgeClient::SCOPES),
                'The token is only used from this machine. It is never printed or saved to the state file.',
            ]);
        }

        while (true) {
            if ($token === '') {
                $token = password('Paste your Forge API token', required: true);
            }

            $forge = new ForgeClient($token);

            try {
                if ($forge->hasValidToken()) {
                    break;
                }

                $this->fail('Forge rejected that token. Check it was copied in full and hasn\'t expired.');
            } catch (RequestException $e) {
                $this->fail(ForgeClient::describeError($e));
            }

            // A bad token from .env can't be fixed by trying it again.
            $token = '';
            $fromEnvironment = false;
        }

        $organizations = $forge->listOrganizations();

        if ($organizations === []) {
            throw new MigrationException('This Forge token can\'t see any organizations.');
        }

        $options = collect($organizations)->mapWithKeys(fn (array $org) => [
            $org['attributes']['slug'] => $org['attributes']['name'],
        ])->all();

        $organization = $context->get('forge.organization') ?? (config('cloud.forge.organization') ?: env('FORGE_ORGANIZATION'));

        if (! isset($options[$organization])) {
            $organization = count($options) === 1
                ? array_key_first($options)
                : select('Which Forge organization is the site in?', $options);
        }

        $forge->forOrganization((string) $organization);
        $context->setForge($forge);
        $context->put('forge.organization', $organization);

        $this->success("Forge token works. Organization: {$options[$organization]}");

        if (! $fromEnvironment) {
            $this->offerToSave($context, ['FORGE_API_TOKEN' => $token, 'FORGE_ORGANIZATION' => (string) $organization]);
        }
    }

    protected function connectCloud(MigrationContext $context): void
    {
        $token = (string) (config('cloud.token') ?: env('LARAVEL_CLOUD_TOKEN'));
        $fromEnvironment = $token !== '';

        if (! $fromEnvironment) {
            $this->explain(
                'LARAVEL_CLOUD_TOKEN isn\'t set.',
                'In Laravel Cloud, open your organization settings > API tokens and click "Create API token".',
                'Cloud tokens belong to one organization, so create it in the organization you want to move the site to.',
            );
        }

        while (true) {
            if ($token === '') {
                $token = password('Paste your Laravel Cloud API token', required: true);
            }

            $cloud = new CloudClient($token);

            if (! $cloud->hasValidToken()) {
                $this->fail('Cloud rejected that token. Check it was copied in full and hasn\'t expired or been revoked.');
                $token = '';
                $fromEnvironment = false;

                continue;
            }

            $organization = $cloud->getOrganization()['data'] ?? [];
            $name = $organization['attributes']['name'] ?? 'unknown';

            if ($context->get('cloud.organization_id') === ($organization['id'] ?? null)
                || confirm("The token belongs to the Cloud organization \"{$name}\". Is that where the site should go?")) {
                break;
            }

            $this->explain('Create a token in the organization you want to use, then paste it here.');
            $token = '';
            $fromEnvironment = false;
        }

        config(['cloud.token' => $token]);
        $context->setCloud($cloud);
        $context->put('cloud.organization_id', $organization['id'] ?? null);

        $this->success("Cloud token works. Organization: {$name}");

        if (! $fromEnvironment) {
            $this->offerToSave($context, ['LARAVEL_CLOUD_TOKEN' => $token]);
        }
    }

    /**
     * The Cloud API can't tell us which source control providers are
     * connected, so look for evidence and otherwise ask.
     */
    protected function checkGitHub(MigrationContext $context): void
    {
        if ($context->get('github.connected')) {
            return;
        }

        $applications = $context->cloud()->all('/applications');

        if ($applications !== []) {
            $this->success(sprintf(
                'Your Cloud organization already has %d application(s), so a source control provider is connected. '
                .'The API doesn\'t say which one. If it isn\'t GitHub, creating the app will fail and you\'ll be shown how to connect it.',
                count($applications)
            ));

            $context->put('github.connected', 'assumed');

            return;
        }

        $this->explain(...[
            'Cloud deploys straight from GitHub, so GitHub has to be connected to your Cloud account before the app can be created.',
            ...self::CLOUD_SOURCE_CONTROL_HELP,
            'This can only be done in the dashboard; the Cloud API has no endpoint for it. It will be confirmed when the app is created.',
        ]);

        while (! confirm('Is GitHub connected to Laravel Cloud?', default: false)) {
            if (! confirm('Keep waiting while you connect it?', default: true)) {
                throw new MigrationException('Connect GitHub to Laravel Cloud, then run this command again.', self::CLOUD_SOURCE_CONTROL_HELP);
            }
        }

        $context->put('github.connected', 'confirmed');
    }

    protected function checkLocalTools(MigrationContext $context): void
    {
        $missing = array_filter(['ssh', 'ssh-keygen'], fn (string $tool) => ! Process::run('command -v '.$tool)->successful());

        if ($missing !== []) {
            $message = 'Copying the database and files needs '.implode(' and ', $missing).' on this machine.';

            if ($context->dryRun) {
                $this->warn($message);

                return;
            }

            throw new MigrationException($message, ['Install OpenSSH and run the command again.']);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function offerToSave(MigrationContext $context, array $values): void
    {
        if ($context->dryRun) {
            return;
        }

        $keys = implode(' and ', array_keys($values));

        if (! confirm("Save {$keys} to this project's .env so you don't have to paste it again?")) {
            return;
        }

        EnvFile::put($context->path('.env'), $values);

        // Make the values visible to env() for the rest of this run.
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $this->success("Saved {$keys} to .env. Keep .env out of version control.");
    }
}
