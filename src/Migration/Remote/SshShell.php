<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Remote;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs scripts on a server over SSH with a specific private key.
 */
class SshShell implements RemoteShell
{
    public function __construct(
        protected string $host,
        protected string $user,
        protected int $port,
        protected string $keyPath,
        protected string $knownHostsPath,
    ) {}

    public function run(string $script, ?callable $onOutput = null, int $timeout = 3600): ProcessResult
    {
        return Process::timeout($timeout)
            ->input("set -o pipefail\n".$script)
            ->run([...$this->sshArguments(), $this->destination(), 'bash -s'], $onOutput);
    }

    public function sshArguments(): array
    {
        return [
            'ssh',
            '-i', $this->keyPath,
            '-p', (string) $this->port,
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'ConnectTimeout=15',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile='.$this->knownHostsPath,
        ];
    }

    public function destination(): string
    {
        return "{$this->user}@{$this->host}";
    }
}
