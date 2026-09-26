<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * A config value that should be written as an env() call rather than a literal.
 */
class EnvExpression
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $default = null,
    ) {}

    public function toPhp(): string
    {
        return $this->default === null
            ? "env('{$this->key}')"
            : "env('{$this->key}', ".var_export($this->default, true).')';
    }
}
