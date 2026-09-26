<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads a local directory to a Cloud bucket over the S3 API.
 *
 * This is the fallback when the Forge server has neither rclone nor the
 * AWS CLI: files are rsynced down to this machine first and uploaded from
 * here. It needs league/flysystem-aws-s3-v3, which apps using Cloud
 * buckets already require.
 */
class BucketUploader
{
    public static function available(): bool
    {
        return class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class);
    }

    /**
     * @param  array{bucket: string, endpoint: string, key: string, secret: string}  $bucket
     */
    public static function disk(array $bucket): Filesystem
    {
        return Storage::build([
            'driver' => 's3',
            'key' => $bucket['key'],
            'secret' => $bucket['secret'],
            'region' => 'auto',
            'bucket' => $bucket['bucket'],
            'endpoint' => $bucket['endpoint'],
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);
    }

    /**
     * Upload every file under $directory, skipping ones already in the
     * bucket with the same size and a modification time at least as new.
     *
     * @return array{uploaded: int, skipped: int}
     */
    public static function upload(Filesystem $disk, string $directory): array
    {
        $existing = [];

        foreach ($disk->allFiles() as $path) {
            $existing[$path] = true;
        }

        $uploaded = 0;
        $skipped = 0;

        foreach (File::allFiles($directory, true) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

            if ($file->getFilename() === '.gitignore') {
                continue;
            }

            if (isset($existing[$path])
                && $disk->size($path) === $file->getSize()
                && $disk->lastModified($path) >= $file->getMTime()) {
                $skipped++;

                continue;
            }

            $stream = fopen($file->getPathname(), 'r');

            try {
                $disk->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $uploaded++;
        }

        return ['uploaded' => $uploaded, 'skipped' => $skipped];
    }
}
