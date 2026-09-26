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
        public readonly mixed $default = null,
    ) {}

    /**
     * Stands in for env() when ConfigFile loads an existing config, so the
     * call is kept rather than replaced with the value (maybe a secret).
     */
    public static function capture(string $key, mixed $default = null): self
    {
        return new self($key, $default);
    }

    public function toPhp(): string
    {
        return $this->default === null
            ? "env('{$this->key}')"
            : "env('{$this->key}', ".var_export($this->default, true).')';
    }
}
