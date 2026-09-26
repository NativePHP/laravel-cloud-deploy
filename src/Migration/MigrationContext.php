<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NativePhp\LaravelCloudDeploy\CloudClient;
use NativePhp\LaravelCloudDeploy\CloudState;
use NativePhp\LaravelCloudDeploy\ForgeClient;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;

/**
 * Everything a migration step needs: the API clients, the saved progress
 * and the command running it.
 *
 * Progress lives under the "migration" key of .laravel-cloud.json. That
 * file is meant to be committed, so nothing secret is ever put in it.
 */
class MigrationContext
{
    protected ?ForgeClient $forge = null;

    protected ?CloudClient $cloud = null;

    /**
     * Set by a step that finished but wants the run to stop after it, so
     * the user can do something (like edit config) before carrying on.
     */
    public bool $pause = false;

    public function __construct(
        public readonly Command $command,
        public readonly CloudState $state,
        public readonly string $projectPath,
        public readonly bool $dryRun = false,
    ) {}

    /**
     * Read a value from the migration's saved progress.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state->get("migration.{$key}", $default);
    }

    /**
     * Store a value in the migration's progress and save it.
     */
    public function put(string $key, mixed $value): void
    {
        $this->state->set("migration.{$key}", $value);
        $this->save();
    }

    /**
     * Remove a value from the migration's progress and save it.
     */
    public function forget(string $key): void
    {
        $this->state->forget("migration.{$key}");
        $this->save();
    }

    /**
     * Save progress. A dry run keeps everything in memory.
     */
    public function save(): void
    {
        if ($this->dryRun) {
            return;
        }

        $this->state->touch();
        $this->state->save();
    }

    public function setForge(ForgeClient $forge): void
    {
        $this->forge = $forge;
    }

    public function forge(): ForgeClient
    {
        return $this->forge ?? throw new \LogicException('The Forge client is set up by the setup step.');
    }

    public function setCloud(CloudClient $cloud): void
    {
        $this->cloud = $cloud;
    }

    public function cloud(): CloudClient
    {
        return $this->cloud ?? throw new \LogicException('The Cloud client is set up by the setup step.');
    }

    /**
     * What the inspect step found on Forge.
     *
     * @return array<string, mixed>
     */
    public function inspection(): array
    {
        return $this->get('inspection') ?? throw new MigrationException('The Forge site hasn\'t been inspected yet.', [
            'Run the command without --step to go through the steps in order.',
        ]);
    }

    /**
     * The Cloud environment's ID, once provisioning has created it.
     */
    public function environmentId(): string
    {
        return $this->state->getEnvironmentId(ConfigGenerator::ENVIRONMENT)
            ?? throw new MigrationException('The Cloud environment doesn\'t exist yet.', [
                'Run the provision step first: php artisan cloud:migrate-from-forge --step=provision',
            ]);
    }

    /**
     * Path to a file in the project being migrated.
     */
    public function path(string $relative = ''): string
    {
        return rtrim($this->projectPath, '/').($relative === '' ? '' : '/'.ltrim($relative, '/'));
    }

    /**
     * Record something that must be undone even if the run crashes, like a
     * temporary SSH key or a database opened to the internet.
     *
     * @param  array<string, mixed>  $details
     */
    public function registerCleanup(string $name, array $details): void
    {
        $this->put("cleanup.{$name}", $details);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cleanup(string $name): ?array
    {
        return $this->get("cleanup.{$name}");
    }

    public function clearCleanup(string $name): void
    {
        $this->forget("cleanup.{$name}");
    }

    /**
     * Load the project's config/cloud.php fresh from disk, since the
     * migration writes it after the app has booted.
     *
     * @return array<string, mixed>
     */
    public function cloudConfig(): array
    {
        $path = $this->path('config/cloud.php');

        if (! File::exists($path)) {
            throw new MigrationException('config/cloud.php doesn\'t exist yet.', [
                'Run the config step first: php artisan cloud:migrate-from-forge --step=config',
            ]);
        }

        return require $path;
    }
}
