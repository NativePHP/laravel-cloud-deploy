<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case BuildPending = 'build.pending';
    case BuildCreated = 'build.created';
    case BuildQueued = 'build.queued';
    case BuildRunning = 'build.running';
    case BuildSucceeded = 'build.succeeded';
    case BuildFailed = 'build.failed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case DeploymentPending = 'deployment.pending';
    case DeploymentCreated = 'deployment.created';
    case DeploymentQueued = 'deployment.queued';
    case DeploymentRunning = 'deployment.running';
    case DeploymentSucceeded = 'deployment.succeeded';
    case DeploymentFailed = 'deployment.failed';

    /**
     * Whether a raw status string from the API is terminal.
     *
     * Statuses the enum doesn't know yet are treated as terminal only when
     * they look like a stage failure, so a new failure stage can't leave a
     * caller polling until its timeout.
     */
    public static function isTerminalValue(string $status): bool
    {
        return self::tryFrom($status)?->isTerminal() ?? str_ends_with($status, '.failed');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::DeploymentSucceeded,
            self::DeploymentFailed,
            self::BuildFailed,
            self::Failed,
            self::Cancelled,
        ]);
    }

    public function isSuccessful(): bool
    {
        return $this === self::DeploymentSucceeded;
    }

    public function isPending(): bool
    {
        return ! $this->isTerminal();
    }

    public function color(): string
    {
        return match ($this) {
            self::DeploymentSucceeded => 'green',
            self::DeploymentFailed, self::BuildFailed, self::Failed => 'red',
            self::Cancelled => 'gray',
            default => 'yellow',
        };
    }
}
