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

    /**
     * The state as it was last read from or written to disk.
     *
     * @var array<string, mixed>
     */
    protected array $persisted = [];

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
        $this->state = [];

        if (File::exists($this->statePath)) {
            $contents = File::get($this->statePath);
            $this->state = json_decode($contents, true) ?? [];
        }

        // Older versions wrote a timestamp and the last deployment ID on
        // every run, so the file changed on every deploy. Neither is kept
        // any more; they drop out the next time an ID changes.
        unset($this->state['updated_at']);

        foreach (array_keys($this->state['environments'] ?? []) as $environment) {
            unset($this->state['environments'][$environment]['last_deployment_id']);
        }

        $this->persisted = $this->state;
    }

    /**
     * Save the state to disk if any ID has changed since it was loaded or last saved.
     *
     * Returns whether the file was written.
     */
    public function save(): bool
    {
        if (! $this->isDirty()) {
            return false;
        }

        File::put(
            $this->statePath,
            json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );

        $this->persisted = $this->state;

        return true;
    }

    /**
     * Whether the state differs from what is on disk.
     */
    public function isDirty(): bool
    {
        // Loose comparison, so key order doesn't count as a change.
        return $this->state != $this->persisted;
    }

    /**
     * The path of the state file.
     */
    public function path(): string
    {
        return $this->statePath;
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
     * Get a database cluster ID by the name it has in config/cloud.php.
     */
    public function getDatabaseClusterId(string $name): ?string
    {
        return $this->state['databases'][$name]['id'] ?? null;
    }

    /**
     * Set a database cluster ID.
     */
    public function setDatabaseClusterId(string $name, string $id): void
    {
        $this->state['databases'][$name]['id'] = $id;
    }

    /**
     * Get the ID of a database (schema) in a cluster.
     */
    public function getDatabaseSchemaId(string $cluster, string $schema): ?string
    {
        return $this->state['databases'][$cluster]['schemas'][$schema]['id'] ?? null;
    }

    /**
     * Set the ID of a database (schema) in a cluster.
     */
    public function setDatabaseSchemaId(string $cluster, string $schema, string $id): void
    {
        $this->state['databases'][$cluster]['schemas'][$schema]['id'] = $id;
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
        $this->persisted = [];
    }
}
