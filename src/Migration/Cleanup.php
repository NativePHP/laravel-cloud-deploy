<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration;

use NativePhp\LaravelCloudDeploy\Migration\Remote\SshAccess;
use NativePhp\LaravelCloudDeploy\Migration\Steps\DatabaseStep;

/**
 * Undoes temporary changes a previous run didn't get to clean up.
 */
class Cleanup
{
    /**
     * @return array<int, string> What was cleaned up
     */
    public static function runPending(MigrationContext $context): array
    {
        $done = [];

        if ($context->cleanup(SshAccess::CLEANUP)) {
            SshAccess::close($context);
            $done[] = 'Removed the temporary SSH key from the Forge server.';
        }

        if ($context->cleanup(DatabaseStep::CLEANUP)) {
            DatabaseStep::closePublicAccess($context);
            $done[] = 'Turned public access to the Cloud database back off.';
        }

        return $done;
    }
}
