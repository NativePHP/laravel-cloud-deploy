<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * One step of the Forge to Cloud migration.
 */
abstract class Step
{
    /**
     * The name used with --step and in the state file.
     */
    abstract public function name(): string;

    /**
     * A short title for the step's heading.
     */
    abstract public function title(): string;

    /**
     * What the step is about to do and why, shown before it runs.
     */
    abstract public function explanation(): string;

    /**
     * Run the step.
     *
     * @return bool True when the step finished, false when the user chose to
     *              stop (the next run picks up at this step again)
     */
    abstract public function handle(MigrationContext $context): bool;

    /**
     * Whether the step runs during --dry-run. Steps that would create or
     * change anything don't.
     */
    public function runsInDryRun(): bool
    {
        return false;
    }

    /**
     * Show an explanation, one paragraph per line.
     */
    protected function explain(string ...$lines): void
    {
        note(implode(PHP_EOL, $lines));
    }

    protected function success(string $message): void
    {
        info($message);
    }

    protected function warn(string $message): void
    {
        warning($message);
    }

    protected function fail(string $message): void
    {
        error($message);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    protected function table(array $headers, array $rows): void
    {
        table($headers, $rows);
    }
}
