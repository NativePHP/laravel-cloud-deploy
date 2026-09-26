<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;

/**
 * Shows a spinner while something slow runs.
 *
 * The Prompts spinner forks a process to animate, which is pointless when
 * output isn't a terminal (CI, logs, tests), so then just print the message.
 */
class Spinner
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback, string $message): mixed
    {
        if (app()->runningUnitTests() || ! defined('STDOUT') || ! stream_isatty(STDOUT)) {
            note($message);

            return $callback();
        }

        return spin($callback(...), $message);
    }
}
