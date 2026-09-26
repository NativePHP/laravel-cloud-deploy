<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Decides how the Forge site's queue workers run on Cloud.
 *
 * Managed queues are the default, as Laravel's migration guide recommends:
 * Cloud runs the workers, scales them with the queue and down to zero, and
 * sets QUEUE_CONNECTION=cloud. Each managed queue handles one queue name.
 *
 * A worker stays a worker process on the app instance when managed queues
 * can't take its jobs: Horizon is in charge of the queues, the app's
 * Laravel is too old, the worker reads a connection other than the default
 * one, or the queue name isn't one Cloud accepts.
 */
class QueuePlanner
{
    /**
     * The first release of each major that supports managed queues.
     */
    public const MIN_LARAVEL = ['11' => '11.55.0', '12' => '12.63.0', '13' => '13.19.0'];

    /**
     * Flex queue workers stop jobs after this many seconds.
     */
    public const FLEX_JOB_SECONDS = 90;

    /**
     * @param  array<string, mixed>  $inspection
     * @return array{
     *     managed: array<string, array{max_replicas: int, size: string}>,
     *     workers: array<int, array<string, mixed>>,
     *     reasons: array<int, string>,
     *     notes: array<int, string>
     * }
     */
    public static function plan(array $inspection): array
    {
        $plan = ['managed' => [], 'workers' => [], 'reasons' => [], 'notes' => []];
        $workers = array_values(array_filter($inspection['processes'] ?? [], fn (array $process) => $process['kind'] === ProcessClassifier::WORKER));

        if ($workers === []) {
            return $plan;
        }

        $blocker = self::reasonToKeepWorkers($inspection);

        if ($blocker !== null) {
            $plan['workers'] = $workers;
            $plan['reasons'][] = $blocker;

            return $plan;
        }

        $default = $inspection['env']['facts']['QUEUE_CONNECTION'] ?? 'sync';
        $timeouts = [];
        $retrySettings = false;

        foreach ($workers as $worker) {
            $queue = $worker['queue'];
            $connection = $queue['connection'] ?? $default;

            if ($connection !== $default) {
                $plan['workers'][] = $worker;
                $plan['reasons'][] = "The worker for \"{$connection}\" reads that connection explicitly. Managed queues only receive jobs sent to the default connection, so it stays a worker process.";

                continue;
            }

            $invalid = array_filter($queue['queues'], fn (string $name) => ! preg_match('/^[A-Za-z0-9_-]{3,39}$/', $name));

            if ($invalid !== []) {
                $plan['workers'][] = $worker;
                $plan['reasons'][] = 'Cloud queue names must be 3 to 39 letters, numbers, hyphens or underscores, so the worker for "'
                    .implode(', ', $invalid).'" stays a worker process.';

                continue;
            }

            foreach ($queue['queues'] as $name) {
                $plan['managed'][$name]['max_replicas'] = max($plan['managed'][$name]['max_replicas'] ?? 1, $worker['processes']);
                $timeouts[$name] = max($timeouts[$name] ?? 0, (int) ($queue['timeout'] ?? 60));
            }

            $retrySettings = $retrySettings || $queue['tries'] !== null || $queue['backoff'] !== null;
        }

        foreach ($plan['managed'] as $name => $queue) {
            $plan['managed'][$name]['size'] = $timeouts[$name] > self::FLEX_JOB_SECONDS ? 'mq.pro.256mb' : 'mq.flex.256mb';

            if ($timeouts[$name] > self::FLEX_JOB_SECONDS) {
                $plan['notes'][] = "The \"{$name}\" worker allows jobs {$timeouts[$name]} seconds. Flex queue workers stop jobs at 90 seconds, so it uses a Pro size, which needs the Growth plan or higher.";
            }
        }

        // The first managed queue created becomes the environment's default,
        // which is where jobs dispatched without a queue name go.
        if (isset($plan['managed']['default'])) {
            $plan['managed'] = ['default' => $plan['managed']['default']] + $plan['managed'];
        }

        if ($plan['managed'] === []) {
            return $plan;
        }

        if ($retrySettings) {
            $plan['notes'][] = 'Managed queues have no --tries or --backoff setting. Set $tries and $backoff on your job classes if you relied on the worker options.';
        }

        if (count($plan['managed']) > 1) {
            $plan['notes'][] = 'The Starter plan allows one managed queue per environment; this site needs '.count($plan['managed']).'.';
        }

        if (($inspection['project']['has_aws_sdk'] ?? null) === false) {
            $plan['notes'][] = 'Managed queues need the aws/aws-sdk-php package. Run "composer require aws/aws-sdk-php" and push it before deploying.';
        }

        if (($inspection['project']['laravel_version'] ?? null) === null) {
            $plan['notes'][] = 'Couldn\'t read the Laravel version from composer.lock. Managed queues need Laravel 11.55, 12.63, 13.19 or newer.';
        }

        $plan['notes'][] = 'Once a managed queue exists Cloud sets QUEUE_CONNECTION=cloud, so every job dispatched without an explicit connection goes to it.';

        return $plan;
    }

    /**
     * Why every worker has to stay a worker process, if anything.
     *
     * @param  array<string, mixed>  $inspection
     */
    protected static function reasonToKeepWorkers(array $inspection): ?string
    {
        $usesHorizon = ($inspection['integrations']['horizon'] ?? false)
            || collect($inspection['processes'] ?? [])->contains('kind', ProcessClassifier::HORIZON);

        if ($usesHorizon) {
            return 'The site uses Horizon, which managed queues don\'t support, so queue workers stay as worker processes.';
        }

        $version = $inspection['project']['laravel_version'] ?? null;

        if ($version !== null && ! self::laravelSupportsManagedQueues($version)) {
            return "The app runs Laravel {$version}. Managed queues need 11.55, 12.63, 13.19 or newer, so queue workers stay as worker processes until you upgrade.";
        }

        return null;
    }

    public static function laravelSupportsManagedQueues(string $version): bool
    {
        $version = ltrim($version, 'v');
        $major = explode('.', $version)[0];

        if (isset(self::MIN_LARAVEL[$major])) {
            return version_compare($version, self::MIN_LARAVEL[$major], '>=');
        }

        return (int) $major > 13;
    }
}
