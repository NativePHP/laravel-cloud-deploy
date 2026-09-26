<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\CloudClient;
use NativePhp\LaravelCloudDeploy\CloudState;
use NativePhp\LaravelCloudDeploy\Enums\DeploymentStatus;

class CloudDeployCommand extends Command
{
    protected $signature = 'cloud:deploy
                            {environment? : The environment to deploy (defaults to all configured environments)}
                            {--skip-deploy : Configure infrastructure without initiating a deployment}
                            {--force : Skip confirmation prompts}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Deploy your application to Laravel Cloud based on config/cloud.php';

    protected CloudClient $client;

    protected CloudState $state;

    protected bool $isDryRun = false;

    /**
     * Environments whose deployment did not succeed.
     *
     * @var array<int, string>
     */
    protected array $failedDeployments = [];

    /**
     * How long to wait for a new database cluster or cache to become available.
     */
    public static int $resourceTimeoutSeconds = 1200;

    /**
     * How often to poll a new database cluster or cache for its status.
     */
    public static int $resourcePollSeconds = 10;

    public function handle(): int
    {
        $this->isDryRun = $this->option('dry-run');

        if (! $this->validateConfig()) {
            return self::FAILURE;
        }

        try {
            $this->client = new CloudClient(config('cloud.token'));
            $this->state = new CloudState;

            $this->ensureApplication();

            $environments = $this->getEnvironmentsToDeploy();

            foreach ($environments as $envName => $envConfig) {
                $this->deployEnvironment($envName, $envConfig);
            }

            if (! $this->isDryRun) {
                $this->state->touch();
                $this->state->save();
            }

            $this->newLine();

            if ($this->failedDeployments !== []) {
                $this->error('Deployment failed for: '.implode(', ', $this->failedDeployments));

                return self::FAILURE;
            }

            $this->info('Deployment complete!');

            return self::SUCCESS;
        } catch (RequestException $e) {
            $this->error('API Error: '.$e->response->json('message', $e->getMessage()));

            // Show validation errors if present
            $errors = $e->response->json('errors', []);
            foreach ($errors as $field => $messages) {
                foreach ((array) $messages as $message) {
                    if ($message) {
                        $this->line("  - {$field}: {$message}");
                    }
                }
            }

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function validateConfig(): bool
    {
        $token = config('cloud.token');

        if (empty($token)) {
            $this->error('LARAVEL_CLOUD_TOKEN is not set in your .env file.');
            $this->line('Generate a token in your Laravel Cloud organization settings, under "API tokens".');

            return false;
        }

        $repository = config('cloud.application.repository');

        if (empty($repository)) {
            $this->error('LARAVEL_CLOUD_REPOSITORY is not set in your .env file.');
            $this->line('Set it to your GitHub repository in "owner/repo" format.');

            return false;
        }

        $environments = config('cloud.environments', []);

        if (empty($environments)) {
            $this->error('No environments configured in config/cloud.php.');

            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getEnvironmentsToDeploy(): array
    {
        $allEnvironments = config('cloud.environments', []);
        $requestedEnv = $this->argument('environment');

        if ($requestedEnv) {
            if (! isset($allEnvironments[$requestedEnv])) {
                throw new \InvalidArgumentException("Environment '{$requestedEnv}' is not configured.");
            }

            return [$requestedEnv => $allEnvironments[$requestedEnv]];
        }

        return $allEnvironments;
    }

    protected function ensureApplication(): void
    {
        $repository = config('cloud.application.repository');
        $name = config('cloud.application.name');
        $region = config('cloud.application.region');

        $this->info("Checking application: {$name}");

        $applicationId = $this->state->getApplicationId();

        if ($applicationId) {
            $this->line("  Using cached application ID: {$applicationId}");

            return;
        }

        $app = $this->client->findApplicationByRepository($repository);

        if ($app) {
            $applicationId = $app['id'];
            $this->line("  Found existing application: {$applicationId}");
            $this->state->setApplicationId($applicationId);
            $this->state->save();

            return;
        }

        if ($this->isDryRun) {
            $this->warn("  [DRY RUN] Would create application: {$name}");

            return;
        }

        if (! $this->option('force') && ! $this->confirm("Application not found. Create '{$name}'?")) {
            throw new \RuntimeException('Aborted.');
        }

        $this->line("  Creating application: {$name}");

        $response = $this->client->createApplication([
            'source_control_provider_type' => config('cloud.application.source_control', 'github'),
            'repository' => $repository,
            'name' => $name,
            'region' => $region,
        ]);

        $applicationId = $response['data']['id'];
        $this->state->setApplicationId($applicationId);
        $this->state->save();
        $this->info("  Created application: {$applicationId}");
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function deployEnvironment(string $name, array $config): void
    {
        $this->newLine();
        $this->info("Deploying environment: {$name}");

        $environmentId = $this->ensureEnvironment($name, $config);

        if (! $environmentId && $this->isDryRun) {
            $this->warn("  [DRY RUN] Skipping further configuration for {$name}");

            return;
        }

        $this->configureEnvironment($environmentId, $config);
        $this->attachResources($name, $environmentId);
        $this->syncEnvironmentVariables($name, $environmentId);
        $this->configureInstances($name, $environmentId, $config['instances'] ?? []);
        $this->configureDomains($name, $environmentId, $config['domains'] ?? []);

        if (! $this->option('skip-deploy')) {
            $this->initiateDeployment($name, $environmentId);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function ensureEnvironment(string $name, array $config): ?string
    {
        $applicationId = $this->state->getApplicationId();

        if (! $applicationId && $this->isDryRun) {
            return null;
        }

        $environmentId = $this->state->getEnvironmentId($name);

        if ($environmentId) {
            $this->line("  Using cached environment ID: {$environmentId}");

            return $environmentId;
        }

        $env = $this->client->findEnvironmentByName($applicationId, $name);

        if ($env) {
            $environmentId = $env['id'];
            $this->line("  Found existing environment: {$environmentId}");
            $this->state->setEnvironmentId($name, $environmentId);
            $this->state->save();

            return $environmentId;
        }

        if ($this->isDryRun) {
            $this->warn("  [DRY RUN] Would create environment: {$name}");

            return null;
        }

        $this->line("  Creating environment: {$name}");

        $response = $this->client->createEnvironment($applicationId, [
            'name' => $name,
            'branch' => $config['branch'] ?? 'main',
        ]);

        $environmentId = $response['data']['id'];
        $this->state->setEnvironmentId($name, $environmentId);
        $this->state->save();
        $this->info("  Created environment: {$environmentId}");

        return $environmentId;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function configureEnvironment(string $environmentId, array $config): void
    {
        $this->line('  Configuring environment settings...');

        $updateData = [];

        if (isset($config['branch'])) {
            $updateData['branch'] = $config['branch'];
        }

        if (isset($config['slug'])) {
            $updateData['slug'] = $config['slug'];
        }

        if (isset($config['push_to_deploy'])) {
            $updateData['uses_push_to_deploy'] = $config['push_to_deploy'];
        }

        if (isset($config['php'])) {
            $updateData['php_version'] = $config['php'];
        }

        if (isset($config['node'])) {
            $updateData['node_version'] = $config['node'];
        }

        if (isset($config['build_commands'])) {
            $updateData['build_command'] = implode(' && ', $config['build_commands']);
        }

        if (isset($config['deploy_commands'])) {
            $updateData['deploy_command'] = implode(' && ', $config['deploy_commands']);
        }

        if (isset($config['octane'])) {
            $updateData['uses_octane'] = $config['octane'];
        }

        if (isset($config['timeout'])) {
            $updateData['timeout'] = $config['timeout'];
        }

        if (isset($config['vanity_domain'])) {
            $updateData['uses_vanity_domain'] = $config['vanity_domain'];
        }

        if (isset($config['network'])) {
            $network = $config['network'];

            if (isset($network['cache_strategy'])) {
                $updateData['cache_strategy'] = $network['cache_strategy'];
            }

            if (isset($network['purge_cache_on_deploy'])) {
                $updateData['uses_purge_edge_cache_on_deploy'] = $network['purge_cache_on_deploy'];
            }

            if (isset($network['response_headers'])) {
                $headers = $network['response_headers'];

                if (isset($headers['frame'])) {
                    $updateData['response_headers_frame'] = $headers['frame'];
                }

                if (isset($headers['content_type'])) {
                    $updateData['response_headers_content_type'] = $headers['content_type'];
                }

                if (isset($headers['robots_tag'])) {
                    $updateData['response_headers_robots_tag'] = $headers['robots_tag'];
                }

                if (isset($headers['hsts']) && ($headers['hsts']['enabled'] ?? false)) {
                    $updateData['response_headers_hsts'] = [
                        'max_age' => $headers['hsts']['max_age'] ?? 31536000,
                        'include_subdomains' => $headers['hsts']['include_subdomains'] ?? true,
                        'preload' => $headers['hsts']['preload'] ?? true,
                    ];
                }
            }

            if (isset($network['firewall'])) {
                $firewall = $network['firewall'];

                if (isset($firewall['block_path'])) {
                    $updateData['firewall_block_path'] = $firewall['block_path'];
                }

                if (isset($firewall['browser_integrity_check'])) {
                    $updateData['firewall_browser_integrity_check'] = $firewall['browser_integrity_check'];
                }
            }
        }

        if (empty($updateData)) {
            $this->line('    No configuration changes needed.');

            return;
        }

        if ($this->isDryRun) {
            $this->warn('    [DRY RUN] Would update environment with: '.json_encode($updateData));

            return;
        }

        $this->client->updateEnvironment($environmentId, $updateData);
        $this->line('    Environment configured.');
    }

    protected function syncEnvironmentVariables(string $envName, string $environmentId): void
    {
        $globalVars = config('cloud.variables.global', []);
        $envVars = config("cloud.variables.{$envName}", []);

        $allVars = array_merge($globalVars, $envVars);

        if (empty($allVars)) {
            return;
        }

        $this->line('  Syncing environment variables...');

        $variables = [];

        foreach ($allVars as $key => $value) {
            if ($value !== null) {
                $variables[] = ['key' => $key, 'value' => (string) $value];
            }
        }

        if ($this->isDryRun) {
            $this->warn('    [DRY RUN] Would sync '.count($variables).' variables');

            return;
        }

        // "set" updates keys that already exist, so re-running a deploy
        // doesn't pile up duplicate variables the way "append" would.
        $this->client->setEnvironmentVariables($environmentId, $variables);
        $this->line('    Synced '.count($variables).' variables.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $instances
     */
    protected function configureInstances(string $envName, string $environmentId, array $instances): void
    {
        if (empty($instances)) {
            return;
        }

        $this->line('  Configuring instances...');

        foreach ($instances as $instanceName => $instanceConfig) {
            $this->configureInstance($envName, $environmentId, $instanceName, $instanceConfig);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function configureInstance(string $envName, string $environmentId, string $name, array $config): void
    {
        $instanceId = $this->state->getInstanceId($envName, $name);

        if (! $instanceId) {
            $instance = $this->client->findInstanceByName($environmentId, $name);

            if ($instance) {
                $instanceId = $instance['id'];
                $this->state->setInstanceId($envName, $name, $instanceId);
                $this->state->save();
            }
        }

        $instanceData = $this->buildInstanceData($config, creating: ! $instanceId);

        if ($instanceId) {
            $this->line("    Updating instance: {$name}");

            if ($this->isDryRun) {
                $this->warn('      [DRY RUN] Would update instance with: '.json_encode($instanceData));

                return;
            }

            $this->client->updateInstance($instanceId, $instanceData);
        } else {
            $this->line("    Creating instance: {$name}");

            if ($this->isDryRun) {
                $this->warn('      [DRY RUN] Would create instance with: '.json_encode($instanceData));

                return;
            }

            $instanceData['name'] = $name;
            $response = $this->client->createInstance($environmentId, $instanceData);
            $instanceId = $response['data']['id'];
            $this->state->setInstanceId($envName, $name, $instanceId);
            $this->state->save();
        }

        if (! empty($config['processes'])) {
            $this->configureBackgroundProcesses($envName, $name, $instanceId, $config['processes']);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function buildInstanceData(array $config, bool $creating): array
    {
        $data = [];

        // The type can only be set when creating an instance (service or
        // managed_queue); the update endpoint doesn't accept it.
        if ($creating && isset($config['type'])) {
            $data['type'] = $config['type'];
        }

        if (isset($config['size'])) {
            $data['size'] = $config['size'];
        }

        if (isset($config['scheduler'])) {
            $data['uses_scheduler'] = $config['scheduler'];
        }

        if (isset($config['scaling']) || $creating) {
            $scaling = $config['scaling'] ?? [];
            $data['scaling_type'] = $scaling['type'] ?? 'none';

            // Replica counts are rejected when auto-scaling.
            if ($data['scaling_type'] !== 'auto') {
                $data['min_replicas'] = $scaling['min_replicas'] ?? 1;
                $data['max_replicas'] = $scaling['max_replicas'] ?? 1;
            }

            if (isset($scaling['cpu_threshold'])) {
                $data['scaling_cpu_threshold_percentage'] = $scaling['cpu_threshold'];
            }

            if (isset($scaling['memory_threshold'])) {
                $data['scaling_memory_threshold_percentage'] = $scaling['memory_threshold'];
            }
        }

        if ($creating) {
            // Required on create, even though only managed queues use them.
            $data['visibility_timeout'] = $config['visibility_timeout'] ?? null;
            $data['shutdown_timeout'] = $config['shutdown_timeout'] ?? null;
        } elseif (array_key_exists('hibernation_timeout', $config)) {
            // Minutes before the instance hibernates; null turns it off.
            $data['hibernation_timeout'] = $config['hibernation_timeout'];
        }

        return $data;
    }

    /**
     * @param  array<string, array<string, mixed>>  $processes
     */
    protected function configureBackgroundProcesses(string $envName, string $instanceName, string $instanceId, array $processes): void
    {
        foreach ($processes as $processName => $processConfig) {
            $processId = $this->state->getProcessId($envName, $instanceName, $processName);

            $processData = $this->buildProcessData($processConfig);

            if ($processId) {
                $this->line("      Updating process: {$processName}");

                if ($this->isDryRun) {
                    $this->warn('        [DRY RUN] Would update process');

                    continue;
                }

                $this->client->updateBackgroundProcess($processId, $processData);
            } else {
                $this->line("      Creating process: {$processName}");

                if ($this->isDryRun) {
                    $this->warn('        [DRY RUN] Would create process');

                    continue;
                }

                $response = $this->client->createBackgroundProcess($instanceId, $processData);
                $processId = $response['data']['id'];
                $this->state->setProcessId($envName, $instanceName, $processName, $processId);
                $this->state->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function buildProcessData(array $config): array
    {
        $data = [
            'type' => $config['type'] ?? 'worker',
            'processes' => $config['processes'] ?? 1,
        ];

        if (isset($config['command'])) {
            $data['command'] = $config['command'];
        }

        if (isset($config['queue'])) {
            $queue = $config['queue'];
            $data['config'] = [
                'connection' => $queue['connection'] ?? 'redis',
                'queue' => implode(',', (array) ($queue['queues'] ?? ['default'])),
                'tries' => $queue['tries'] ?? 3,
                'backoff' => $queue['backoff'] ?? 30,
                'sleep' => $queue['sleep'] ?? 3,
                'rest' => $queue['rest'] ?? 0,
                'timeout' => $queue['timeout'] ?? 60,
                'force' => $queue['force'] ?? false,
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, array<string, mixed>>  $domains
     */
    protected function configureDomains(string $envName, string $environmentId, array $domains): void
    {
        if (empty($domains)) {
            return;
        }

        $this->line('  Configuring domains...');

        foreach ($domains as $domainName => $domainConfig) {
            $domainId = $this->state->getDomainId($envName, $domainName);

            if (! $domainId) {
                $domain = $this->client->findDomainByName($environmentId, $domainName);

                if ($domain) {
                    $domainId = $domain['id'];
                    $this->state->setDomainId($envName, $domainName, $domainId);
                    $this->state->save();
                }
            }

            if ($domainId) {
                // The API only lets you change a domain's verification method.
                // Redirect and wildcard settings are fixed once it exists.
                if (! isset($domainConfig['verification_method'])) {
                    $this->line("    Domain exists: {$domainName}");

                    continue;
                }

                $this->line("    Updating domain: {$domainName}");

                if ($this->isDryRun) {
                    $this->warn('      [DRY RUN] Would update domain');

                    continue;
                }

                $this->client->updateDomain($domainId, [
                    'verification_method' => $domainConfig['verification_method'],
                ]);
            } else {
                $domainData = array_filter([
                    'name' => $domainName,
                    'www_redirect' => $domainConfig['www_redirect'] ?? null,
                    'wildcard_enabled' => $domainConfig['wildcard'] ?? false,
                    'verification_method' => $domainConfig['verification_method'] ?? null,
                ], fn ($value) => $value !== null);

                $this->line("    Creating domain: {$domainName}");

                if ($this->isDryRun) {
                    $this->warn('      [DRY RUN] Would create domain');

                    continue;
                }

                $response = $this->client->createDomain($environmentId, $domainData);
                $domainId = $response['data']['id'];
                $this->state->setDomainId($envName, $domainName, $domainId);
                $this->state->save();
            }
        }
    }

    /**
     * Create the database, cache and bucket resources this environment uses
     * and attach them to it.
     *
     * Resources are shared across environments and identified by their key
     * in config/cloud.php. Their IDs are cached in the state file, so
     * running a deploy again reuses them rather than creating duplicates.
     */
    protected function attachResources(string $envName, string $environmentId): void
    {
        $attachments = [];

        foreach (config('cloud.databases', []) as $key => $config) {
            $schema = $this->schemaNameFor($config, $envName);

            if ($schema === null) {
                continue;
            }

            $attachments['database_schema_id'] = $this->ensureDatabase((string) $key, $config, $schema);
        }

        foreach (config('cloud.caches', []) as $key => $config) {
            if (in_array($envName, $config['environments'] ?? [], true)) {
                $attachments['cache_id'] = $this->ensureCache((string) $key, $config);
            }
        }

        $filesystemKeys = [];

        foreach (config('cloud.buckets', []) as $key => $config) {
            if (! in_array($envName, $config['environments'] ?? [], true)) {
                continue;
            }

            $keyId = $this->ensureBucket((string) $key, $config);

            $filesystemKeys[] = [
                'id' => $keyId,
                'disk' => $config['disk'] ?? (string) $key,
                'is_default_disk' => (bool) ($config['default'] ?? false),
            ];
        }

        if ($filesystemKeys !== []) {
            $attachments['filesystem_keys'] = $filesystemKeys;
        }

        $attachments = array_filter($attachments, fn ($value) => $value !== null);

        if ($attachments === []) {
            return;
        }

        if ($this->isDryRun) {
            $this->warn('    [DRY RUN] Would attach resources: '.implode(', ', array_keys($attachments)));

            return;
        }

        $this->line('  Attaching resources...');
        $this->client->updateEnvironment($environmentId, $attachments);
        $this->line('    Attached: '.implode(', ', array_keys($attachments)));
    }

    /**
     * The name of the database (schema) an environment should use in a cluster,
     * or null when the environment doesn't use the cluster.
     *
     * "environments" is either a list of environment names, each getting a
     * database named after the environment, or a map of environment name to
     * database name.
     *
     * @param  array<string, mixed>  $config
     */
    protected function schemaNameFor(array $config, string $envName): ?string
    {
        $environments = $config['environments'] ?? [];

        if (array_is_list($environments)) {
            return in_array($envName, $environments, true) ? $envName : null;
        }

        return isset($environments[$envName]) ? (string) $environments[$envName] : null;
    }

    /**
     * Make sure a database cluster and a database (schema) in it exist.
     *
     * @param  array<string, mixed>  $config
     * @return string|null The database (schema) ID, or null in a dry run
     */
    protected function ensureDatabase(string $key, array $config, string $schema): ?string
    {
        $name = $config['name'] ?? $key;
        $clusterId = $this->state->get("resources.databases.{$key}.id");

        $this->line("  Checking database cluster: {$name}");

        if (! $clusterId) {
            $clusterId = $this->client->findDatabaseClusterByName($name)['id'] ?? null;
        }

        if (! $clusterId) {
            if ($this->isDryRun) {
                $this->warn("    [DRY RUN] Would create {$config['type']} database cluster: {$name}");

                return null;
            }

            $type = $config['type'] ?? 'laravel_mysql';

            $response = $this->client->createDatabaseCluster([
                'type' => $type,
                'version' => $config['version'] ?? $this->latestDatabaseVersion($type),
                'name' => $name,
                'region' => $config['region'] ?? config('cloud.application.region'),
                'config' => $config['config'] ?? [],
                // Neon clusters can't skip the default database.
                'create_default_database' => $type === 'neon_serverless_postgres',
            ]);

            $clusterId = $response['data']['id'];
            $this->info("    Created database cluster: {$clusterId}");
        }

        $this->state->set("resources.databases.{$key}.id", $clusterId);
        $this->state->save();

        $schemaId = $this->state->get("resources.databases.{$key}.schemas.{$schema}");

        if ($schemaId) {
            return $schemaId;
        }

        $this->waitForResource(
            fn () => $this->client->getDatabaseCluster($clusterId)['data']['attributes']['status'] ?? 'unknown',
            "database cluster {$name}"
        );

        $schemaId = $this->client->findDatabaseByName($clusterId, $schema)['id'] ?? null;

        if (! $schemaId) {
            if ($this->isDryRun) {
                $this->warn("    [DRY RUN] Would create database: {$schema}");

                return null;
            }

            $schemaId = $this->client->createDatabase($clusterId, $schema)['data']['id'];
            $this->line("    Created database: {$schema}");
        }

        $this->state->set("resources.databases.{$key}.schemas.{$schema}", $schemaId);
        $this->state->save();

        return $schemaId;
    }

    /**
     * Pick the newest version the API offers for a database type.
     */
    protected function latestDatabaseVersion(string $type): string
    {
        foreach ($this->client->listDatabaseTypes()['data'] ?? [] as $databaseType) {
            if (($databaseType['type'] ?? null) !== $type) {
                continue;
            }

            $versions = $databaseType['versions'] ?? [];
            usort($versions, 'version_compare');

            if ($versions !== []) {
                return (string) end($versions);
            }
        }

        throw new \RuntimeException("Cloud didn't list any versions for the {$type} database type. Set a version in config/cloud.php.");
    }

    /**
     * Make sure a cache exists.
     *
     * @param  array<string, mixed>  $config
     * @return string|null The cache ID, or null in a dry run
     */
    protected function ensureCache(string $key, array $config): ?string
    {
        $name = $config['name'] ?? $key;
        $cacheId = $this->state->get("resources.caches.{$key}.id");

        $this->line("  Checking cache: {$name}");

        if (! $cacheId) {
            $cacheId = $this->client->findCacheByName($name)['id'] ?? null;
        }

        if (! $cacheId) {
            if ($this->isDryRun) {
                $this->warn("    [DRY RUN] Would create cache: {$name}");

                return null;
            }

            $response = $this->client->createCache([
                'type' => $config['type'] ?? 'laravel_valkey',
                'name' => $name,
                'region' => $config['region'] ?? config('cloud.application.region'),
                'size' => $config['size'] ?? 'valkey-flex-250mb',
                'auto_upgrade_enabled' => (bool) ($config['auto_upgrade_enabled'] ?? true),
                'is_public' => (bool) ($config['is_public'] ?? false),
            ]);

            $cacheId = $response['data']['id'];
            $this->info("    Created cache: {$cacheId}");

            $this->waitForResource(
                fn () => $this->client->getCache($cacheId)['data']['attributes']['status'] ?? 'unknown',
                "cache {$name}"
            );
        }

        $this->state->set("resources.caches.{$key}.id", $cacheId);
        $this->state->save();

        return $cacheId;
    }

    /**
     * Make sure a bucket and an access key for it exist.
     *
     * @param  array<string, mixed>  $config
     * @return string|null The access key ID, or null in a dry run
     */
    protected function ensureBucket(string $key, array $config): ?string
    {
        $name = $config['name'] ?? $key;
        $bucketId = $this->state->get("resources.buckets.{$key}.id");
        $keyId = $this->state->get("resources.buckets.{$key}.key_id");

        $this->line("  Checking bucket: {$name}");

        if ($bucketId && $keyId) {
            return $keyId;
        }

        if (! $bucketId) {
            $bucketId = $this->client->findBucketByName($name)['id'] ?? null;
        }

        if ($this->isDryRun) {
            if (! $bucketId) {
                $this->warn("    [DRY RUN] Would create bucket: {$name}");
            }

            return null;
        }

        $keyName = "{$name}-key";

        if (! $bucketId) {
            $response = $this->client->createBucket([
                'name' => $name,
                'visibility' => $config['visibility'] ?? 'private',
                'jurisdiction' => $config['jurisdiction'] ?? 'default',
                'key_name' => $keyName,
                'key_permission' => 'read_write',
            ]);

            $bucketId = $response['data']['id'];
            $keyId = collect($response['included'] ?? [])->firstWhere('type', 'filesystemKeys')['id']
                ?? $response['data']['relationships']['keys']['data'][0]['id']
                ?? null;

            $this->info("    Created bucket: {$bucketId}");
        } else {
            $keyId = collect($this->client->listBucketKeys($bucketId)['data'] ?? [])
                ->first(fn ($key) => ($key['attributes']['permission'] ?? null) === 'read_write')['id'] ?? null;
        }

        if (! $keyId) {
            $keyId = $this->client->createBucketKey($bucketId, [
                'name' => $keyName,
                'permission' => 'read_write',
            ])['data']['id'];
        }

        $this->state->set("resources.buckets.{$key}.id", $bucketId);
        $this->state->set("resources.buckets.{$key}.key_id", $keyId);
        $this->state->save();

        return $keyId;
    }

    /**
     * Poll a newly created resource until Cloud reports it as available.
     *
     * @param  callable(): string  $status
     */
    protected function waitForResource(callable $status, string $label): void
    {
        if ($this->isDryRun) {
            return;
        }

        $deadline = time() + static::$resourceTimeoutSeconds;
        $current = $status();

        while ($current !== 'available') {
            if (in_array($current, ['stopped', 'disabled', 'deleting', 'deleted', 'restore_failed'], true)) {
                throw new \RuntimeException("The {$label} is {$current}, so it can't be used.");
            }

            if (time() >= $deadline) {
                throw new \RuntimeException("Timed out waiting for the {$label} to become available (last status: {$current}).");
            }

            $this->line("    Waiting for {$label} ({$current})...");
            Sleep::for(static::$resourcePollSeconds)->seconds();
            $current = $status();
        }
    }

    protected function initiateDeployment(string $envName, string $environmentId): void
    {
        $this->line('  Initiating deployment...');

        if ($this->isDryRun) {
            $this->warn('    [DRY RUN] Would initiate deployment');

            return;
        }

        $response = $this->client->initiateDeployment($environmentId);
        $deploymentId = $response['data']['id'];

        $this->state->setLastDeploymentId($envName, $deploymentId);
        $this->state->save();
        $this->info("    Deployment initiated: {$deploymentId}");

        $this->line('    Waiting for deployment to complete...');

        $deployment = $this->client->waitForDeployment(
            $deploymentId,
            timeoutSeconds: 600,
            pollIntervalSeconds: 5,
            onStatusChange: function (string $status) {
                $this->line("      Status: {$status}");
            }
        );

        $finalStatus = $deployment['data']['attributes']['status'] ?? 'unknown';

        if (DeploymentStatus::tryFrom($finalStatus)?->isSuccessful()) {
            $this->info('    Deployment successful!');
        } else {
            $failureReason = $deployment['data']['attributes']['failure_reason'] ?? 'Unknown error';
            $this->error("    Deployment failed: {$failureReason}");
            $this->failedDeployments[] = $envName;
        }
    }
}
