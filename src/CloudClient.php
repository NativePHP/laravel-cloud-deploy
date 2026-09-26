<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use NativePhp\LaravelCloudDeploy\Enums\CommandStatus;
use NativePhp\LaravelCloudDeploy\Enums\DeploymentStatus;

class CloudClient
{
    /**
     * The maximum number of variables the API accepts in a single request.
     */
    public const MAX_VARIABLES_PER_REQUEST = 200;

    protected string $baseUrl = 'https://cloud.laravel.com/api';

    protected PendingRequest $http;

    public function __construct(
        protected string $token
    ) {
        $this->http = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->contentType('application/json')
            ->throw();
    }

    /**
     * Fetch every item of a paginated list endpoint by following `links.next`.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function all(string $path, array $query = []): array
    {
        $items = [];
        $response = $this->http->get($path, $query)->json();

        while (true) {
            array_push($items, ...($response['data'] ?? []));

            $next = $response['links']['next'] ?? null;

            if (! $next) {
                return $items;
            }

            $response = $this->http->get($next)->json();
        }
    }

    /**
     * List all applications.
     *
     * @return array<string, mixed>
     */
    public function listApplications(): array
    {
        return $this->http->get('/applications')->json();
    }

    /**
     * Find an application by repository name.
     */
    public function findApplicationByRepository(string $repository): ?array
    {
        foreach ($this->all('/applications') as $app) {
            $repoFullName = $app['attributes']['repository']['full_name'] ?? null;

            if ($repoFullName === $repository) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Create a new application.
     *
     * @param  array{source_control_provider_type?: string, repository: string, name: string, region: string}  $data
     * @return array<string, mixed>
     */
    public function createApplication(array $data): array
    {
        return $this->http->post('/applications', $data)->json();
    }

    /**
     * Get an application by ID.
     *
     * @return array<string, mixed>
     */
    public function getApplication(string $applicationId): array
    {
        return $this->http->get("/applications/{$applicationId}")->json();
    }

    /**
     * Update an application.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateApplication(string $applicationId, array $data): array
    {
        return $this->http->patch("/applications/{$applicationId}", $data)->json();
    }

    /**
     * Delete an application.
     */
    public function deleteApplication(string $applicationId): Response
    {
        return $this->http->delete("/applications/{$applicationId}");
    }

    /**
     * List environments for an application.
     *
     * @return array<string, mixed>
     */
    public function listEnvironments(string $applicationId): array
    {
        return $this->http->get("/applications/{$applicationId}/environments")->json();
    }

    /**
     * Find an environment by name.
     */
    public function findEnvironmentByName(string $applicationId, string $name): ?array
    {
        foreach ($this->all("/applications/{$applicationId}/environments") as $env) {
            if (($env['attributes']['name'] ?? null) === $name) {
                return $env;
            }
        }

        return null;
    }

    /**
     * Create a new environment.
     *
     * @param  array{branch: string, name: string}  $data
     * @return array<string, mixed>
     */
    public function createEnvironment(string $applicationId, array $data): array
    {
        return $this->http->post("/applications/{$applicationId}/environments", $data)->json();
    }

    /**
     * Get an environment by ID.
     *
     * @return array<string, mixed>
     */
    public function getEnvironment(string $environmentId): array
    {
        return $this->http->get("/environments/{$environmentId}")->json();
    }

    /**
     * Update an environment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateEnvironment(string $environmentId, array $data): array
    {
        return $this->http->patch("/environments/{$environmentId}", $data)->json();
    }

    /**
     * Delete an environment.
     */
    public function deleteEnvironment(string $environmentId): Response
    {
        return $this->http->delete("/environments/{$environmentId}");
    }

    /**
     * Add environment variables without checking for existing keys.
     *
     * The API does not de-duplicate appended keys, so calling this twice
     * with the same key leaves two copies. Use setEnvironmentVariables()
     * to create or update variables idempotently.
     *
     * @param  array<int, array{key: string, value: string}>  $variables
     * @return array<string, mixed> The response for the last chunk sent
     */
    public function addEnvironmentVariables(string $environmentId, array $variables): array
    {
        return $this->storeEnvironmentVariables($environmentId, $variables, 'append');
    }

    /**
     * Create or update environment variables, replacing the values of keys that already exist.
     *
     * @param  array<int, array{key: string, value: string}>  $variables
     * @return array<string, mixed> The response for the last chunk sent
     */
    public function setEnvironmentVariables(string $environmentId, array $variables): array
    {
        return $this->storeEnvironmentVariables($environmentId, $variables, 'set');
    }

    /**
     * Send environment variables in chunks the API will accept.
     *
     * @param  array<int, array{key: string, value: string}>  $variables
     * @param  'append'|'set'  $method
     * @return array<string, mixed>
     */
    protected function storeEnvironmentVariables(string $environmentId, array $variables, string $method): array
    {
        $response = [];

        foreach (array_chunk($variables, self::MAX_VARIABLES_PER_REQUEST) as $chunk) {
            $response = $this->http->post("/environments/{$environmentId}/variables", [
                'method' => $method,
                'variables' => $chunk,
            ])->json();
        }

        return $response;
    }

    /**
     * Delete environment variables by key.
     *
     * The API treats this as atomic: if any key does not exist, nothing is
     * deleted and the request fails with a 422.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function deleteEnvironmentVariables(string $environmentId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->http->post("/environments/{$environmentId}/variables/delete", [
            'keys' => array_values($keys),
        ])->json();
    }

    /**
     * List instances for an environment.
     *
     * @return array<string, mixed>
     */
    public function listInstances(string $environmentId): array
    {
        return $this->http->get("/environments/{$environmentId}/instances")->json();
    }

    /**
     * Find an instance by name.
     */
    public function findInstanceByName(string $environmentId, string $name): ?array
    {
        foreach ($this->all("/environments/{$environmentId}/instances") as $instance) {
            if (($instance['attributes']['name'] ?? null) === $name) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Create a new instance.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createInstance(string $environmentId, array $data): array
    {
        return $this->http->post("/environments/{$environmentId}/instances", $data)->json();
    }

    /**
     * Get an instance by ID.
     *
     * @return array<string, mixed>
     */
    public function getInstance(string $instanceId): array
    {
        return $this->http->get("/instances/{$instanceId}")->json();
    }

    /**
     * Update an instance.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateInstance(string $instanceId, array $data): array
    {
        return $this->http->patch("/instances/{$instanceId}", $data)->json();
    }

    /**
     * Delete an instance.
     */
    public function deleteInstance(string $instanceId): Response
    {
        return $this->http->delete("/instances/{$instanceId}");
    }

    /**
     * List background processes for an instance.
     *
     * @return array<string, mixed>
     */
    public function listBackgroundProcesses(string $instanceId): array
    {
        return $this->http->get("/instances/{$instanceId}/background-processes")->json();
    }

    /**
     * Create a background process.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createBackgroundProcess(string $instanceId, array $data): array
    {
        return $this->http->post("/instances/{$instanceId}/background-processes", $data)->json();
    }

    /**
     * Update a background process.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateBackgroundProcess(string $processId, array $data): array
    {
        return $this->http->patch("/background-processes/{$processId}", $data)->json();
    }

    /**
     * Delete a background process.
     */
    public function deleteBackgroundProcess(string $processId): Response
    {
        return $this->http->delete("/background-processes/{$processId}");
    }

    /**
     * List domains for an environment.
     *
     * @return array<string, mixed>
     */
    public function listDomains(string $environmentId): array
    {
        return $this->http->get("/environments/{$environmentId}/domains")->json();
    }

    /**
     * Find a domain by name.
     */
    public function findDomainByName(string $environmentId, string $name): ?array
    {
        foreach ($this->all("/environments/{$environmentId}/domains") as $domain) {
            if (($domain['attributes']['name'] ?? null) === $name) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Create a domain.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createDomain(string $environmentId, array $data): array
    {
        return $this->http->post("/environments/{$environmentId}/domains", $data)->json();
    }

    /**
     * Update a domain.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateDomain(string $domainId, array $data): array
    {
        return $this->http->patch("/domains/{$domainId}", $data)->json();
    }

    /**
     * Delete a domain.
     */
    public function deleteDomain(string $domainId): Response
    {
        return $this->http->delete("/domains/{$domainId}");
    }

    /**
     * Verify a domain.
     *
     * @return array<string, mixed>
     */
    public function verifyDomain(string $domainId): array
    {
        return $this->http->post("/domains/{$domainId}/verify")->json();
    }

    /**
     * List deployments for an environment.
     *
     * @return array<string, mixed>
     */
    public function listDeployments(string $environmentId): array
    {
        return $this->http->get("/environments/{$environmentId}/deployments")->json();
    }

    /**
     * Initiate a deployment.
     *
     * @return array<string, mixed>
     */
    public function initiateDeployment(string $environmentId): array
    {
        return $this->http->post("/environments/{$environmentId}/deployments")->json();
    }

    /**
     * Get a deployment by ID.
     *
     * @return array<string, mixed>
     */
    public function getDeployment(string $deploymentId): array
    {
        return $this->http->get("/deployments/{$deploymentId}")->json();
    }

    /**
     * Get the build and deploy logs for a deployment.
     *
     * @return array<string, mixed>
     */
    public function getDeploymentLogs(string $deploymentId): array
    {
        return $this->http->get("/deployments/{$deploymentId}/logs")->json();
    }

    /**
     * Get the status of a deployment as an enum.
     */
    public function getDeploymentStatus(string $deploymentId): DeploymentStatus
    {
        $deployment = $this->getDeployment($deploymentId);
        $status = $deployment['data']['attributes']['status'] ?? 'pending';

        return DeploymentStatus::from($status);
    }

    /**
     * Wait for a deployment to reach a terminal status.
     *
     * @param  callable|null  $onStatusChange  Called when status changes
     * @return array<string, mixed> The final deployment state
     */
    public function waitForDeployment(
        string $deploymentId,
        int $timeoutSeconds = 600,
        int $pollIntervalSeconds = 5,
        ?callable $onStatusChange = null
    ): array {
        $startTime = time();
        $lastStatus = null;

        while (time() - $startTime < $timeoutSeconds) {
            $deployment = $this->getDeployment($deploymentId);
            $status = $deployment['data']['attributes']['status'] ?? 'unknown';

            if ($status !== $lastStatus && $onStatusChange) {
                $onStatusChange($status, $deployment);
                $lastStatus = $status;
            }

            if (DeploymentStatus::isTerminalValue($status)) {
                return $deployment;
            }

            sleep($pollIntervalSeconds);
        }

        throw new \RuntimeException("Deployment timed out after {$timeoutSeconds} seconds");
    }

    /**
     * Run a command on an environment.
     *
     * @return array<string, mixed>
     */
    public function runCommand(string $environmentId, string $command): array
    {
        return $this->http->post("/environments/{$environmentId}/commands", [
            'command' => $command,
        ])->json();
    }

    /**
     * Get a command by ID.
     *
     * @return array<string, mixed>
     */
    public function getCommand(string $commandId): array
    {
        return $this->http->get("/commands/{$commandId}")->json();
    }

    /**
     * Get the status of a command as an enum.
     */
    public function getCommandStatus(string $commandId): CommandStatus
    {
        $command = $this->getCommand($commandId);
        $status = $command['data']['attributes']['status'] ?? 'pending';

        return CommandStatus::from($status);
    }

    /**
     * List commands for an environment.
     *
     * @return array<string, mixed>
     */
    public function listCommands(string $environmentId): array
    {
        return $this->http->get("/environments/{$environmentId}/commands")->json();
    }

    /**
     * List database clusters.
     *
     * @param  array<string, mixed>  $query  Optional filters, e.g. ['filter[type]' => 'laravel_mysql']
     * @return array<string, mixed>
     */
    public function listDatabaseClusters(array $query = []): array
    {
        return $this->http->get('/databases/clusters', $query)->json();
    }

    /**
     * Find a database cluster by name.
     */
    public function findDatabaseClusterByName(string $name): ?array
    {
        foreach ($this->all('/databases/clusters') as $cluster) {
            if (($cluster['attributes']['name'] ?? null) === $name) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * List the database types that can be created, with their versions and config schemas.
     *
     * @return array<string, mixed>
     */
    public function listDatabaseTypes(): array
    {
        return $this->http->get('/databases/types')->json();
    }

    /**
     * Create a new database cluster.
     *
     * @param  array{type: string, version: string, name: string, region: string, config: array<string, mixed>, create_default_database?: bool}  $data
     * @return array<string, mixed>
     */
    public function createDatabaseCluster(array $data): array
    {
        return $this->http->post('/databases/clusters', $data)->json();
    }

    /**
     * Get a database cluster by ID.
     *
     * @return array<string, mixed>
     */
    public function getDatabaseCluster(string $clusterId): array
    {
        return $this->http->get("/databases/clusters/{$clusterId}")->json();
    }

    /**
     * Update a database cluster.
     *
     * The API expects the full `config` object for the cluster type, so read
     * the current config and change the keys you need (e.g. `is_public`).
     *
     * @param  array{config: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function updateDatabaseCluster(string $clusterId, array $data): array
    {
        return $this->http->patch("/databases/clusters/{$clusterId}", $data)->json();
    }

    /**
     * Delete a database cluster.
     */
    public function deleteDatabaseCluster(string $clusterId): Response
    {
        return $this->http->delete("/databases/clusters/{$clusterId}");
    }

    /**
     * List the databases (schemas) in a database cluster.
     *
     * @return array<string, mixed>
     */
    public function listDatabases(string $clusterId): array
    {
        return $this->http->get("/databases/clusters/{$clusterId}/databases")->json();
    }

    /**
     * Create a database (schema) in a database cluster.
     *
     * Attach it to an environment with updateEnvironment($id, ['database_schema_id' => ...]).
     *
     * @return array<string, mixed>
     */
    public function createDatabase(string $clusterId, string $name): array
    {
        return $this->http->post("/databases/clusters/{$clusterId}/databases", [
            'name' => $name,
        ])->json();
    }

    /**
     * Get a database (schema) in a database cluster.
     *
     * @return array<string, mixed>
     */
    public function getDatabase(string $clusterId, string $databaseId): array
    {
        return $this->http->get("/databases/clusters/{$clusterId}/databases/{$databaseId}")->json();
    }
}
