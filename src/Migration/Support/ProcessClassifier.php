<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Works out what a Forge background process or scheduled job is from its
 * command line.
 *
 * Forge's API has no queue worker resource. Workers made with the site's
 * "New Worker" form, Horizon, Octane and Reverb are all Supervisor
 * programs, so they come back as background processes and all we have to
 * go on is the command, e.g.
 * `php8.3 /home/forge/example.com/artisan queue:work redis --queue=default --tries=3`.
 */
class ProcessClassifier
{
    public const WORKER = 'worker';

    public const HORIZON = 'horizon';

    public const OCTANE = 'octane';

    public const REVERB = 'reverb';

    public const ARTISAN = 'artisan';

    public const OTHER = 'other';

    /**
     * Classify a background process command.
     */
    public static function classify(string $command): string
    {
        $artisan = self::artisanCommand($command);

        if ($artisan === null) {
            return self::OTHER;
        }

        $name = strtok($artisan, ' ') ?: '';

        return match (true) {
            in_array($name, ['queue:work', 'queue:listen'], true) => self::WORKER,
            $name === 'horizon' => self::HORIZON,
            str_starts_with($name, 'octane:') => self::OCTANE,
            $name === 'reverb:start' => self::REVERB,
            default => self::ARTISAN,
        };
    }

    /**
     * The artisan command and its arguments, without the PHP binary or path,
     * e.g. "queue:work redis --tries=3". Null when it isn't an artisan command.
     */
    public static function artisanCommand(string $command): ?string
    {
        if (! preg_match('/(?:^|[\s\/])artisan\s+(.+)$/', trim($command), $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * Parse a queue:work / queue:listen command into Cloud worker settings.
     *
     * @return array{connection: string|null, queues: array<int, string>, tries: int|null, backoff: int|null, timeout: int|null, sleep: int|null, rest: int|null, force: bool}
     */
    public static function parseWorker(string $command): array
    {
        $tokens = self::tokens((string) self::artisanCommand($command));
        array_shift($tokens); // queue:work

        $worker = [
            'connection' => null,
            'queues' => [],
            'tries' => null,
            'backoff' => null,
            'timeout' => null,
            'sleep' => null,
            'rest' => null,
            'force' => false,
        ];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (! str_starts_with($token, '--')) {
                $worker['connection'] ??= $token;

                continue;
            }

            [$option, $value] = array_pad(explode('=', substr($token, 2), 2), 2, null);

            // Support "--queue default" as well as "--queue=default".
            if ($value === null && isset($tokens[$i + 1]) && ! str_starts_with($tokens[$i + 1], '--')
                && in_array($option, ['queue', 'tries', 'backoff', 'timeout', 'sleep', 'rest'], true)) {
                $value = $tokens[++$i];
            }

            match ($option) {
                'queue' => $worker['queues'] = array_values(array_filter(array_map('trim', explode(',', (string) $value)))),
                'tries', 'timeout', 'sleep', 'rest' => $worker[$option] = (int) $value,
                // Cloud only takes one backoff value; use the first of a list like "10,60".
                'backoff' => $worker['backoff'] = (int) explode(',', (string) $value)[0],
                'force' => $worker['force'] = true,
                default => null,
            };
        }

        if ($worker['queues'] === []) {
            $worker['queues'] = ['default'];
        }

        return $worker;
    }

    /**
     * Whether a scheduled job runs the Laravel scheduler.
     */
    public static function isScheduler(string $command): bool
    {
        return str_starts_with((string) self::artisanCommand($command), 'schedule:run');
    }

    /**
     * Split a command line into tokens, honouring simple quotes.
     *
     * @return array<int, string>
     */
    public static function tokens(string $command): array
    {
        // A token is a run of unquoted characters and quoted segments, so
        // --queue="high,default" stays one token.
        preg_match_all('/(?:[^\s"\']+|"[^"]*"|\'[^\']*\')+/', $command, $matches);

        return array_map(fn (string $token) => str_replace(['"', "'"], '', $token), $matches[0]);
    }
}
