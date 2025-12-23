<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use NativePhp\LaravelCloudDeploy\Enums\CommandStatus;

class CloudClient
{
    protected string $baseUrl = 'https://app.laravel.cloud/api';

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
        $applications = $this->listApplications();

        foreach ($applications['data'] ?? [] as $app) {
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
     * @param  array{repository: string, name: string, region: string}  $data
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
        $environments = $this->listEnvironments($applicationId);

        foreach ($environments['data'] ?? [] as $env) {
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
     * Add environment variables.
     *
     * @param  array<int, array{key: string, value: string}>  $variables
     * @return array<string, mixed>
     */
    public function addEnvironmentVariables(string $environmentId, array $variables): array
    {
        return $this->http->post("/environments/{$environmentId}/variables", [
            'method' => 'append',
            'variables' => $variables,
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
        $instances = $this->listInstances($environmentId);

        foreach ($instances['data'] ?? [] as $instance) {
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
        $domains = $this->listDomains($environmentId);

        foreach ($domains['data'] ?? [] as $domain) {
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
     * Wait for a deployment to complete.
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

            // Check for terminal statuses (various formats returned by API)
            if (in_array($status, ['deployed', 'failed', 'deployment.succeeded', 'deployment.failed'])) {
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
     * Get IP addresses for whitelisting.
     *
     * @return array<string, array{ipv4: string[], ipv6: string[]}>
     */
    public function getIpAddresses(): array
    {
        return $this->http->get('/ip')->json();
    }

    /**
     * List all database clusters.
     *
     * @return array<string, mixed>
     */
    public function listDatabaseClusters(): array
    {
        return $this->http->get('/databases')->json();
    }

    /**
     * Find a database cluster by name.
     */
    public function findDatabaseClusterByName(string $name): ?array
    {
        $clusters = $this->listDatabaseClusters();

        foreach ($clusters['data'] ?? [] as $cluster) {
            if (($cluster['attributes']['name'] ?? null) === $name) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * Create a new database cluster.
     *
     * @param  array{type: string, name: string, region: string, config: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function createDatabaseCluster(array $data): array
    {
        return $this->http->post('/databases', $data)->json();
    }

    /**
     * Get a database cluster by ID.
     *
     * @return array<string, mixed>
     */
    public function getDatabaseCluster(string $clusterId): array
    {
        return $this->http->get("/databases/{$clusterId}")->json();
    }

    /**
     * Update a database cluster.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateDatabaseCluster(string $clusterId, array $data): array
    {
        return $this->http->patch("/databases/{$clusterId}", $data)->json();
    }

    /**
     * Delete a database cluster.
     */
    public function deleteDatabaseCluster(string $clusterId): Response
    {
        return $this->http->delete("/databases/{$clusterId}");
    }

    /**
     * Get a database cluster with schemas included.
     *
     * @return array<string, mixed>
     */
    public function getDatabaseClusterWithSchemas(string $clusterId): array
    {
        return $this->http->get("/databases/{$clusterId}", [
            'include' => 'schemas',
        ])->json();
    }
}
