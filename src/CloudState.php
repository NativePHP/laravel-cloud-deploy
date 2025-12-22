<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy;

use Illuminate\Support\Facades\File;

class CloudState
{
    protected string $statePath;

    /**
     * @var array<string, mixed>
     */
    protected array $state = [];

    public function __construct(?string $statePath = null)
    {
        $this->statePath = $statePath ?? config('cloud.state_path', base_path('.laravel-cloud.json'));
        $this->load();
    }

    /**
     * Load the state from disk.
     */
    public function load(): void
    {
        if (File::exists($this->statePath)) {
            $contents = File::get($this->statePath);
            $this->state = json_decode($contents, true) ?? [];
        }
    }

    /**
     * Save the state to disk.
     */
    public function save(): void
    {
        File::put(
            $this->statePath,
            json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Check if the state file exists.
     */
    public function exists(): bool
    {
        return File::exists($this->statePath);
    }

    /**
     * Get the application ID.
     */
    public function getApplicationId(): ?string
    {
        return $this->state['application_id'] ?? null;
    }

    /**
     * Set the application ID.
     */
    public function setApplicationId(string $id): void
    {
        $this->state['application_id'] = $id;
    }

    /**
     * Get an environment ID by name.
     */
    public function getEnvironmentId(string $name): ?string
    {
        return $this->state['environments'][$name]['id'] ?? null;
    }

    /**
     * Set an environment ID.
     */
    public function setEnvironmentId(string $name, string $id): void
    {
        if (! isset($this->state['environments'])) {
            $this->state['environments'] = [];
        }

        if (! isset($this->state['environments'][$name])) {
            $this->state['environments'][$name] = [];
        }

        $this->state['environments'][$name]['id'] = $id;
    }

    /**
     * Get an instance ID by environment and instance name.
     */
    public function getInstanceId(string $environment, string $name): ?string
    {
        return $this->state['environments'][$environment]['instances'][$name]['id'] ?? null;
    }

    /**
     * Set an instance ID.
     */
    public function setInstanceId(string $environment, string $name, string $id): void
    {
        if (! isset($this->state['environments'][$environment]['instances'])) {
            $this->state['environments'][$environment]['instances'] = [];
        }

        if (! isset($this->state['environments'][$environment]['instances'][$name])) {
            $this->state['environments'][$environment]['instances'][$name] = [];
        }

        $this->state['environments'][$environment]['instances'][$name]['id'] = $id;
    }

    /**
     * Get a domain ID by environment and domain name.
     */
    public function getDomainId(string $environment, string $name): ?string
    {
        return $this->state['environments'][$environment]['domains'][$name]['id'] ?? null;
    }

    /**
     * Set a domain ID.
     */
    public function setDomainId(string $environment, string $name, string $id): void
    {
        if (! isset($this->state['environments'][$environment]['domains'])) {
            $this->state['environments'][$environment]['domains'] = [];
        }

        $this->state['environments'][$environment]['domains'][$name]['id'] = $id;
    }

    /**
     * Get a background process ID.
     */
    public function getProcessId(string $environment, string $instance, string $name): ?string
    {
        return $this->state['environments'][$environment]['instances'][$instance]['processes'][$name]['id'] ?? null;
    }

    /**
     * Set a background process ID.
     */
    public function setProcessId(string $environment, string $instance, string $name, string $id): void
    {
        if (! isset($this->state['environments'][$environment]['instances'][$instance]['processes'])) {
            $this->state['environments'][$environment]['instances'][$instance]['processes'] = [];
        }

        $this->state['environments'][$environment]['instances'][$instance]['processes'][$name]['id'] = $id;
    }

    /**
     * Get the last deployment ID for an environment.
     */
    public function getLastDeploymentId(string $environment): ?string
    {
        return $this->state['environments'][$environment]['last_deployment_id'] ?? null;
    }

    /**
     * Set the last deployment ID for an environment.
     */
    public function setLastDeploymentId(string $environment, string $id): void
    {
        $this->state['environments'][$environment]['last_deployment_id'] = $id;
    }

    /**
     * Get the entire state array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->state;
    }

    /**
     * Clear the state.
     */
    public function clear(): void
    {
        $this->state = [];
    }

    /**
     * Delete the state file.
     */
    public function delete(): void
    {
        if (File::exists($this->statePath)) {
            File::delete($this->statePath);
        }

        $this->state = [];
    }

    /**
     * Set a timestamp for when the state was last updated.
     */
    public function touch(): void
    {
        $this->state['updated_at'] = now()->toIso8601String();
    }

    /**
     * Get the last update timestamp.
     */
    public function getUpdatedAt(): ?string
    {
        return $this->state['updated_at'] ?? null;
    }
}
