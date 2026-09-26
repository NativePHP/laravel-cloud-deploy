<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Support\Dns;
use NativePhp\LaravelCloudDeploy\Migration\Support\MigrationReport;

use function Laravel\Prompts\confirm;

/**
 * Shows what will migrate, what needs doing by hand and what blocks the move.
 */
class ReportStep extends Step
{
    public function name(): string
    {
        return 'report';
    }

    public function title(): string
    {
        return 'Review what will move';
    }

    public function explanation(): string
    {
        return 'Here is what the migration will carry over, what you\'ll need to handle yourself, and anything that stops it.';
    }

    public function runsInDryRun(): bool
    {
        return true;
    }

    public function handle(MigrationContext $context): bool
    {
        $rows = MigrationReport::build($context->inspection());

        $labels = [
            MigrationReport::MIGRATES => 'Migrates',
            MigrationReport::MANUAL => 'Manual',
            MigrationReport::BLOCKER => 'BLOCKED',
        ];

        $this->table(['', 'Area', 'Details'], array_map(fn (array $row) => [
            $labels[$row['status']],
            $row['area'],
            $row['detail'],
        ], $rows));

        if (MigrationReport::hasBlockers($rows)) {
            throw new MigrationException(
                'This site can\'t be migrated automatically.',
                array_column(array_filter($rows, fn ($row) => $row['status'] === MigrationReport::BLOCKER), 'detail')
            );
        }

        $manual = count(array_filter($rows, fn ($row) => $row['status'] === MigrationReport::MANUAL));

        if ($manual > 0) {
            $this->warn("{$manual} item(s) need you to look at them. They won't stop the migration, but plan to deal with them before cutover.");
        }

        Dns::adviseTtl($context);

        if ($context->dryRun) {
            return true;
        }

        return confirm('Continue with the migration?');
    }
}
