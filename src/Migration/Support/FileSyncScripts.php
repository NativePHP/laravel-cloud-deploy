<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Bash scripts, run on the Forge server, that find the site's stored files
 * and copy them to a Cloud bucket.
 *
 * Bucket credentials go into shell variables at the top of the script and
 * reach rclone / the AWS CLI through their environment variables, never
 * their arguments. The script travels over SSH on stdin.
 */
class FileSyncScripts
{
    /**
     * Print the first directory that holds the Laravel app.
     *
     * @param  array<int, string>  $candidates
     */
    public static function resolveAppPath(array $candidates): string
    {
        $paths = implode(' ', array_map('escapeshellarg', $candidates));

        return "for path in {$paths}; do if [ -f \"\$path/artisan\" ]; then echo \"app:\$path\"; exit 0; fi; done\n";
    }

    /**
     * Print "directory <tab> files <tab> bytes" for each storage directory,
     * plus which upload tools the server has.
     *
     * @param  array<int, array{directory: string, exclude: array<int, string>}>  $sources
     */
    public static function survey(string $appPath, array $sources): string
    {
        $script = 'cd '.escapeshellarg($appPath)." || exit 1\n";

        foreach ($sources as $source) {
            $directory = escapeshellarg($source['directory']);
            $excludes = implode(' ', array_map(
                fn (string $exclude) => '! -path '.escapeshellarg($source['directory'].'/'.$exclude.'/*'),
                $source['exclude']
            ));

            $script .= "if [ -d {$directory} ]; then\n"
                ."    find -L {$directory} -type f ! -name .gitignore {$excludes} -printf '%s\\n' 2>/dev/null"
                ." | awk -v dir={$directory} '{ files++; bytes += \$1 } END { printf \"dir\\t%s\\t%d\\t%d\\n\", dir, files, bytes }'\n"
                ."fi\n";
        }

        return $script
            ."command -v rclone >/dev/null 2>&1 && echo 'tool:rclone'\n"
            ."command -v aws >/dev/null 2>&1 && echo 'tool:aws'\n"
            ."true\n";
    }

    /**
     * Parse survey() output.
     *
     * @return array{directories: array<string, array{files: int, bytes: int}>, tools: array<int, string>}
     */
    public static function parseSurvey(string $output): array
    {
        $result = ['directories' => [], 'tools' => []];

        foreach (preg_split('/\r\n|\n/', trim($output)) ?: [] as $line) {
            $parts = explode("\t", trim($line));

            if ($parts[0] === 'dir' && count($parts) === 4) {
                $result['directories'][$parts[1]] = ['files' => (int) $parts[2], 'bytes' => (int) $parts[3]];
            } elseif (str_starts_with($line, 'tool:')) {
                $result['tools'][] = substr(trim($line), 5);
            }
        }

        return $result;
    }

    /**
     * Copy a directory to a bucket with rclone, skipping files that are
     * already there with the same size and modification time.
     *
     * @param  array<int, string>  $exclude  Subdirectories to leave out
     * @param  array{bucket: string, endpoint: string, key: string, secret: string}  $bucket
     */
    public static function rclone(string $appPath, string $directory, array $exclude, array $bucket): string
    {
        $excludes = implode(' ', array_map(fn ($path) => '--exclude '.escapeshellarg("/{$path}/**"), $exclude));

        return self::credentials($bucket)
            .'cd '.escapeshellarg($appPath)." || exit 1\n"
            ."export RCLONE_CONFIG_CLOUD_TYPE=s3 RCLONE_CONFIG_CLOUD_PROVIDER=Cloudflare RCLONE_CONFIG_CLOUD_NO_CHECK_BUCKET=true\n"
            ."export RCLONE_CONFIG_CLOUD_ENDPOINT=\"\$ENDPOINT\" RCLONE_CONFIG_CLOUD_ACCESS_KEY_ID=\"\$KEY\" RCLONE_CONFIG_CLOUD_SECRET_ACCESS_KEY=\"\$SECRET\"\n"
            ."rclone copy --copy-links --exclude .gitignore {$excludes} ".escapeshellarg($directory)." \"cloud:\$BUCKET\"\n";
    }

    /**
     * Copy a directory to a bucket with the AWS CLI's sync, which skips
     * files whose size and modification time haven't changed.
     *
     * @param  array<int, string>  $exclude  Subdirectories to leave out
     * @param  array{bucket: string, endpoint: string, key: string, secret: string}  $bucket
     */
    public static function aws(string $appPath, string $directory, array $exclude, array $bucket): string
    {
        $excludes = implode(' ', array_map(fn ($path) => '--exclude '.escapeshellarg("{$path}/*"), $exclude));

        return self::credentials($bucket)
            .'cd '.escapeshellarg($appPath)." || exit 1\n"
            ."export AWS_ACCESS_KEY_ID=\"\$KEY\" AWS_SECRET_ACCESS_KEY=\"\$SECRET\" AWS_DEFAULT_REGION=auto\n"
            .'aws s3 sync '.escapeshellarg($directory).' "s3://$BUCKET" --endpoint-url "$ENDPOINT" --only-show-errors'
            ." --exclude .gitignore --exclude '*/.gitignore' {$excludes}\n";
    }

    /**
     * @param  array{bucket: string, endpoint: string, key: string, secret: string}  $bucket
     */
    protected static function credentials(array $bucket): string
    {
        return 'BUCKET='.escapeshellarg($bucket['bucket'])."\n"
            .'ENDPOINT='.escapeshellarg($bucket['endpoint'])."\n"
            .'KEY='.escapeshellarg($bucket['key'])."\n"
            .'SECRET='.escapeshellarg($bucket['secret'])."\n";
    }
}
