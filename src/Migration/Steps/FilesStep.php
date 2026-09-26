<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Steps;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Remote\RemoteShell;
use NativePhp\LaravelCloudDeploy\Migration\Remote\SshAccess;
use NativePhp\LaravelCloudDeploy\Migration\Support\BucketUploader;
use NativePhp\LaravelCloudDeploy\Migration\Support\ConfigGenerator;
use NativePhp\LaravelCloudDeploy\Migration\Support\FileSyncScripts;
use NativePhp\LaravelCloudDeploy\Migration\Support\Spinner;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Copies files from storage/app on the Forge server into the Cloud buckets.
 */
class FilesStep extends Step
{
    public function name(): string
    {
        return 'files';
    }

    public function title(): string
    {
        return 'Copy stored files';
    }

    public function explanation(): string
    {
        return 'Copy files the app stored in storage/app on the Forge server to the Cloud buckets: storage/app/public to the public '
            .'bucket, the rest to the private one. If the server has rclone or the AWS CLI the copy runs there, server to bucket. '
            .'Otherwise files are rsynced to this machine and uploaded from here. Files already copied are skipped, so running it '
            .'again only sends what changed. Nothing on Forge is changed.';
    }

    public function handle(MigrationContext $context): bool
    {
        if ($this->targets($context) === []) {
            $this->success('No buckets are attached to the environment, so there are no files to copy.');

            return true;
        }

        if (! confirm('Copy the stored files to Cloud now?')) {
            return false;
        }

        return $this->sync($context);
    }

    /**
     * Copy the files. Used by this step and by cutover.
     */
    public function sync(MigrationContext $context): bool
    {
        $targets = $this->targets($context);

        if ($targets === []) {
            return true;
        }

        try {
            $shell = (new SshAccess($context))->open();
            $appPath = $this->appPath($context, $shell);
            $sources = $this->sources($targets);

            $survey = FileSyncScripts::parseSurvey($shell->run(FileSyncScripts::survey($appPath, $sources), timeout: 600)->output());

            $this->table(['Directory', 'Files', 'Size'], array_map(fn (string $directory) => [
                $directory,
                (string) ($survey['directories'][$directory]['files'] ?? 0),
                self::humanSize($survey['directories'][$directory]['bytes'] ?? 0),
            ], array_column($sources, 'directory')));

            $credentials = [];

            foreach ($sources as $source) {
                if (($survey['directories'][$source['directory']]['files'] ?? 0) === 0) {
                    continue;
                }

                $credentials[$source['bucket']] ??= $this->bucketCredentials($context, $source['bucket']);

                $this->copy($shell, $appPath, $source, $credentials[$source['bucket']], $survey['tools'], $context);
            }

            $context->put('files.synced_at', now()->toIso8601String());
            $this->success('Files copied.');

            return true;
        } finally {
            SshAccess::close($context);
        }
    }

    /**
     * The buckets attached to production, keyed by "public" and "private".
     *
     * @return array<string, string> role => bucket config key
     */
    protected function targets(MigrationContext $context): array
    {
        $targets = [];

        foreach ($context->cloudConfig()['buckets'] ?? [] as $key => $bucket) {
            if (! in_array(ConfigGenerator::ENVIRONMENT, $bucket['environments'] ?? [], true)) {
                continue;
            }

            $role = ($bucket['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
            $targets[$role] ??= (string) $key;
        }

        return $targets;
    }

    /**
     * Which directories go to which bucket.
     *
     * Laravel 11+ keeps the local disk in storage/app/private; older apps
     * use storage/app itself. Both go to the private bucket.
     *
     * @param  array<string, string>  $targets
     * @return array<int, array{directory: string, exclude: array<int, string>, bucket: string}>
     */
    protected function sources(array $targets): array
    {
        $sources = [];

        if (isset($targets['public'])) {
            $sources[] = ['directory' => 'storage/app/public', 'exclude' => [], 'bucket' => $targets['public']];
        }

        if (isset($targets['private'])) {
            $sources[] = ['directory' => 'storage/app/private', 'exclude' => [], 'bucket' => $targets['private']];
            $sources[] = ['directory' => 'storage/app', 'exclude' => ['public', 'private'], 'bucket' => $targets['private']];
        }

        return $sources;
    }

    /**
     * Find the directory the site's code lives in on the server.
     */
    protected function appPath(MigrationContext $context, RemoteShell $shell): string
    {
        $site = $context->inspection()['site'];
        $candidates = array_values(array_unique(array_filter([
            $context->get('files.app_path'),
            $site['path'].'/current',
            $site['path'],
        ])));

        while (true) {
            $output = $shell->run(FileSyncScripts::resolveAppPath($candidates), timeout: 60)->output();

            if (preg_match('/^app:(.+)$/m', $output, $matches)) {
                $context->put('files.app_path', trim($matches[1]));

                return trim($matches[1]);
            }

            $this->warn('Couldn\'t find the app on the server. Looked in: '.implode(', ', $candidates));

            $candidates = [text('Full path of the site\'s directory on the server (the one containing artisan)', required: true)];
        }
    }

    /**
     * @return array{bucket: string, endpoint: string, key: string, secret: string}
     */
    protected function bucketCredentials(MigrationContext $context, string $key): array
    {
        $bucketId = $context->state->get("resources.buckets.{$key}.id");
        $keyId = $context->state->get("resources.buckets.{$key}.key_id");

        if (! $bucketId || ! $keyId) {
            throw new MigrationException("The {$key} bucket hasn't been created yet.", [
                'Run: php artisan cloud:migrate-from-forge --step=provision',
            ]);
        }

        $bucket = $context->cloud()->getBucket($bucketId)['data']['attributes'] ?? [];
        $accessKey = $context->cloud()->getBucketKey($keyId)['data']['attributes'] ?? [];

        $secret = $accessKey['access_key_secret'] ?? null;

        if (! $secret) {
            $this->explain(
                'Cloud didn\'t return the secret for the bucket\'s access key.',
                'Open Resources > Object storage in Cloud, click "..." next to '.$bucket['name'].' and choose "View credentials".',
            );

            $secret = password('Paste the access key secret for '.$bucket['name'], required: true);
        }

        return [
            'bucket' => (string) $bucket['name'],
            'endpoint' => (string) $bucket['endpoint'],
            'key' => (string) $accessKey['access_key_id'],
            'secret' => $secret,
        ];
    }

    /**
     * @param  array{directory: string, exclude: array<int, string>, bucket: string}  $source
     * @param  array{bucket: string, endpoint: string, key: string, secret: string}  $bucket
     * @param  array<int, string>  $tools
     */
    protected function copy(RemoteShell $shell, string $appPath, array $source, array $bucket, array $tools, MigrationContext $context): void
    {
        $label = "{$source['directory']} to {$bucket['bucket']}";

        if (in_array('rclone', $tools, true) || in_array('aws', $tools, true)) {
            $script = in_array('rclone', $tools, true)
                ? FileSyncScripts::rclone($appPath, $source['directory'], $source['exclude'], $bucket)
                : FileSyncScripts::aws($appPath, $source['directory'], $source['exclude'], $bucket);

            $result = Spinner::run(fn () => $shell->run($script, timeout: 6 * 3600), "Copying {$label} from the Forge server...");

            if (! $result->successful()) {
                throw new MigrationException("Copying {$label} failed: ".trim($result->errorOutput() ?: $result->output()));
            }

            return;
        }

        $this->copyThroughThisMachine($shell, $appPath, $source, $bucket, $context);
    }

    /**
     * rsync the files down, then upload them over the S3 API.
     *
     * @param  array{directory: string, exclude: array<int, string>, bucket: string}  $source
     * @param  array{bucket: string, endpoint: string, key: string, secret: string}  $bucket
     */
    protected function copyThroughThisMachine(RemoteShell $shell, string $appPath, array $source, array $bucket, MigrationContext $context): void
    {
        if (! BucketUploader::available() || ! Process::run('command -v rsync')->successful()) {
            throw new MigrationException('The Forge server has neither rclone nor the AWS CLI, so the files have to go through this machine, which needs rsync and league/flysystem-aws-s3-v3.', [
                'Either install rclone on the server: curl https://rclone.org/install.sh | sudo bash',
                'or install the S3 adapter here: composer require league/flysystem-aws-s3-v3',
                'Then run: php artisan cloud:migrate-from-forge --step=files',
            ]);
        }

        // A stable directory, so a second run only downloads what changed.
        $local = sys_get_temp_dir().'/laravel-cloud-migrate-files/'.$context->get('forge.site_id').'/'.str_replace('/', '_', $source['directory']);
        File::ensureDirectoryExists($local);

        $arguments = ['rsync', '-az', '--copy-links', '--exclude', '.gitignore'];

        foreach ($source['exclude'] as $exclude) {
            array_push($arguments, '--exclude', "/{$exclude}/");
        }

        $remote = $shell->destination().':'.rtrim($appPath, '/').'/'.$source['directory'].'/';
        array_push($arguments, '-e', implode(' ', array_map('escapeshellarg', $shell->sshArguments())), $remote, $local.'/');

        $download = Spinner::run(fn () => Process::timeout(6 * 3600)->run($arguments), "Downloading {$source['directory']}...");

        if (! $download->successful()) {
            throw new MigrationException("Downloading {$source['directory']} failed: ".trim($download->errorOutput()));
        }

        $result = Spinner::run(
            fn () => BucketUploader::upload(BucketUploader::disk($bucket), $local),
            "Uploading to {$bucket['bucket']}..."
        );

        $this->success("Uploaded {$result['uploaded']} file(s), skipped {$result['skipped']} unchanged.");
    }

    protected static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 1).' '.$units[$unit];
    }
}
