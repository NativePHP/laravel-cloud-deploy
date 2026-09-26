<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration;

/**
 * A problem the migration can't get past, with a message meant for the user.
 */
class MigrationException extends \RuntimeException
{
    /**
     * @param  array<int, string>  $hints  Extra lines explaining what to do next
     */
    public function __construct(string $message, public readonly array $hints = [])
    {
        parent::__construct($message);
    }
}
