<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Enums;

enum CommandStatus: string
{
    case Pending = 'pending';
    case Created = 'command.created';
    case Running = 'command.running';
    case Success = 'command.success';
    case Failure = 'command.failure';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failure]);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Pending, self::Created, self::Running]);
    }

    public function color(): string
    {
        return match ($this) {
            self::Success => 'green',
            self::Failure => 'red',
            self::Running, self::Created, self::Pending => 'yellow',
        };
    }
}
