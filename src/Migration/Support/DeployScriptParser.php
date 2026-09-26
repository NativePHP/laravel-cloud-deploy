<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Turns a Forge deploy script into Cloud build and deploy commands.
 *
 * Cloud builds a container image and then runs deploy commands just before
 * the new release goes live. So dependency installs, asset builds and
 * caching belong in the build, migrations belong in the deploy, and the
 * Forge plumbing (git pull, FPM reloads, release macros) goes away.
 */
class DeployScriptParser
{
    /**
     * Artisan commands that should run during the build.
     */
    protected const BUILD_ARTISAN = [
        'optimize', 'config:cache', 'route:cache', 'view:cache', 'event:cache',
        'icons:cache', 'filament:cache-components', 'filament:optimize',
    ];

    /**
     * Artisan commands Cloud makes unnecessary, and why.
     */
    protected const DROPPED_ARTISAN = [
        'queue:restart' => 'Cloud restarts workers on every deploy',
        'horizon:terminate' => 'Cloud restarts Horizon on every deploy',
        'optimize:clear' => 'clearing caches on deploy can break queued jobs; Cloud builds a fresh image',
        'cache:clear' => 'Cloud builds a fresh image on every deploy',
        'config:clear' => 'Cloud builds a fresh image on every deploy',
        'storage:link' => 'the filesystem is rebuilt on every deploy; use a bucket for uploads',
        'down' => 'Cloud deploys without downtime',
        'up' => 'Cloud deploys without downtime',
    ];

    /**
     * @return array{build: array<int, string>, deploy: array<int, string>, dropped: array<int, string>, unmapped: array<int, string>}
     */
    public static function parse(string $script): array
    {
        $result = ['build' => [], 'deploy' => [], 'dropped' => [], 'unmapped' => []];

        foreach (self::statements($script) as $statement) {
            $command = self::normalize($statement);

            if ($command === '' || self::isForgePlumbing($command)) {
                continue;
            }

            // Never copy credentials into config/cloud.php or the state file,
            // both of which get committed.
            if (self::containsCredentials($command)) {
                $result['unmapped'][] = strtok($command, ' ').' ... (this line looks like it contains credentials, so it wasn\'t copied. '
                    .'For private Composer packages, set COMPOSER_AUTH as an environment variable in Cloud instead)';

                continue;
            }

            if (preg_match('/^php artisan (\S+)/', $command, $matches)) {
                $name = $matches[1];

                if (isset(self::DROPPED_ARTISAN[$name])) {
                    $result['dropped'][] = "{$command} ({$name}: ".self::DROPPED_ARTISAN[$name].')';
                } elseif (in_array($name, self::BUILD_ARTISAN, true)) {
                    $result['build'][] = $command;
                } else {
                    $result['deploy'][] = $command;
                }

                continue;
            }

            if (preg_match('/^(composer|npm|npx|yarn|pnpm|bun|node)\b/', $command)) {
                $result['build'][] = $command;

                continue;
            }

            $result['unmapped'][] = $command;
        }

        if (! self::containsComposerInstall($result['build'])) {
            array_unshift($result['build'], 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');
        }

        foreach ($result as $key => $commands) {
            $result[$key] = array_values(array_unique($commands));
        }

        return $result;
    }

    /**
     * Split a script into individual statements.
     *
     * @return array<int, string>
     */
    protected static function statements(string $script): array
    {
        $statements = [];

        foreach (preg_split('/\r\n|\n|\r/', $script) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // "a && b" and "a; b" are separate steps, but "a || b" is one
            // step with a fallback (e.g. "npm ci || npm install").
            foreach (preg_split('/\s*(?:&&|;)\s*/', $line) ?: [] as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $statements[] = $part;
                }
            }
        }

        return $statements;
    }

    /**
     * Replace Forge variables and versioned binaries with plain commands.
     */
    protected static function normalize(string $command): string
    {
        // "if [ -f artisan ]; then php artisan migrate --force; fi" leaves
        // "then php artisan migrate --force" after splitting.
        $command = (string) preg_replace('/^(?:then|else|do)\s+/', '', $command);
        $command = str_replace(['$FORGE_COMPOSER', '${FORGE_COMPOSER}'], 'composer', $command);
        $command = str_replace(['$FORGE_PHP', '${FORGE_PHP}'], 'php', $command);
        $command = (string) preg_replace('/^php\d(?:\.\d+)?\b/', 'php', $command);
        $command = (string) preg_replace('/^(?:\S*\/)?artisan\b/', 'php artisan', $command);
        $command = (string) preg_replace('/^php \S*\/artisan\b/', 'php artisan', $command);

        return trim($command);
    }

    /**
     * Lines that only make sense on a Forge server.
     */
    protected static function isForgePlumbing(string $command): bool
    {
        return (bool) preg_match(
            '/^(?:cd\b|git\b|echo\b|if\b|then\b|else\b|elif\b|fi\b|set\b|touch\b|sudo\b|\(|\)|flock\b|\$[A-Z_]+\(\)|\[|export\b|source\b|\.\s)/',
            $command
        ) || str_contains($command, 'fpmlock');
    }

    /**
     * Whether a line looks like it carries a password, token or secret URL.
     */
    protected static function containsCredentials(string $command): bool
    {
        return (bool) preg_match(
            '/composer\s+config\b.*(?:http-basic|github-oauth|gitlab-token|gitlab-oauth|bearer|bitbucket-oauth)'
            .'|:\/\/[^\s\/:@]+:[^\s\/@]+@'
            .'|hooks\.slack\.com|discord(?:app)?\.com\/api\/webhooks'
            .'|(?:token|password|secret|api[_-]?key)\s*[=:]\s*\S'
            .'|--(?:token|password|secret)[=\s]\S/i',
            $command
        );
    }

    /**
     * @param  array<int, string>  $commands
     */
    protected static function containsComposerInstall(array $commands): bool
    {
        foreach ($commands as $command) {
            if (preg_match('/^composer install\b/', $command)) {
                return true;
            }
        }

        return false;
    }
}
