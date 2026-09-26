<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Remote;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Runs bash scripts on the Forge server.
 *
 * Scripts are sent on stdin rather than as arguments, so passwords in them
 * never show up in the process list on either machine.
 */
interface RemoteShell
{
    /**
     * @param  callable(string $type, string $output): void|null  $onOutput
     */
    public function run(string $script, ?callable $onOutput = null, int $timeout = 3600): ProcessResult;

    /**
     * Arguments for running ssh (or rsync -e) against the same host.
     *
     * @return array<int, string>
     */
    public function sshArguments(): array;

    /**
     * "user@host"
     */
    public function destination(): string;
}
