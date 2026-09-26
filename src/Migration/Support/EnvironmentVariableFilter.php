<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Decides which of a Forge site's .env variables to copy to Cloud.
 *
 * Custom variables in Cloud override the ones Cloud injects for attached
 * resources, so copying the Forge database, Redis or storage settings
 * would point the Cloud app back at the Forge server. Those are dropped.
 */
class EnvironmentVariableFilter
{
    /**
     * @param  array<string, string>  $variables  The parsed Forge .env
     * @param  array{database?: bool, cache?: bool, bucket?: bool, managed_queue?: bool, app_url?: string|null}  $resources
     *                                                                                                                       What the Cloud environment has attached, and the URL to use for APP_URL
     * @return array{keep: array<string, string>, drop: array<string, string>, replaced: array<string, string>, nightwatch_token: string|null}
     *                                                                                                                                         Kept variables with values, and dropped or replaced variable names with the reason
     */
    public static function filter(array $variables, array $resources): array
    {
        $keep = [];
        $drop = [];
        $replaced = [];

        $nightwatchToken = ($variables['NIGHTWATCH_TOKEN'] ?? '') !== '' ? $variables['NIGHTWATCH_TOKEN'] : null;

        foreach ($variables as $key => $value) {
            $reason = self::dropReason($key, $variables, $resources, $nightwatchToken !== null);

            if ($reason !== null) {
                $drop[$key] = $reason;

                continue;
            }

            $keep[$key] = $value;
        }

        if (($resources['app_url'] ?? null) !== null) {
            $keep['APP_URL'] = $resources['app_url'];
            $replaced['APP_URL'] = 'set to '.$resources['app_url'].' until your domain moves over';
        }

        return ['keep' => $keep, 'drop' => $drop, 'replaced' => $replaced, 'nightwatch_token' => $nightwatchToken];
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array<string, mixed>  $resources
     */
    protected static function dropReason(string $key, array $variables, array $resources, bool $usesNightwatch): ?string
    {
        if (($resources['database'] ?? false) && str_starts_with($key, 'DB_')) {
            return 'Cloud injects database credentials';
        }

        if (($resources['cache'] ?? false) && in_array($key, [
            'REDIS_HOST', 'REDIS_PASSWORD', 'REDIS_PORT', 'REDIS_USERNAME', 'REDIS_URL',
            'CACHE_STORE', 'CACHE_DRIVER', 'REDIS_PREFIX', 'HORIZON_PREFIX',
        ], true)) {
            return 'Cloud injects cache settings';
        }

        if ($resources['bucket'] ?? false) {
            if (in_array($key, ['FILESYSTEM_DISK', 'FILESYSTEM_DRIVER'], true)) {
                return 'Cloud injects the bucket disk';
            }

            // Only drop AWS credentials when the app wasn't already on S3.
            // Apps using SES, SQS or their own S3 bucket still need them.
            $alreadyOnS3 = in_array($variables['FILESYSTEM_DISK'] ?? $variables['FILESYSTEM_DRIVER'] ?? '', ['s3'], true);

            if (! $alreadyOnS3 && in_array($key, [
                'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET', 'AWS_ENDPOINT',
                'AWS_ENDPOINT_URL', 'AWS_DEFAULT_REGION', 'AWS_REGION', 'AWS_USE_PATH_STYLE_ENDPOINT',
            ], true)) {
                return 'Cloud injects bucket credentials';
            }
        }

        if (($resources['managed_queue'] ?? false) && $key === 'QUEUE_CONNECTION') {
            return 'Cloud sets QUEUE_CONNECTION=cloud for managed queues';
        }

        if ($usesNightwatch && $key === 'NIGHTWATCH_TOKEN') {
            return 'set as the environment\'s Nightwatch token instead';
        }

        if ($usesNightwatch && $key === 'LOG_CHANNEL') {
            return 'Cloud sets the log channel when Nightwatch is on';
        }

        return null;
    }
}
