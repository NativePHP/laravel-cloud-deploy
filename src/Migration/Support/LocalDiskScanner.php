<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

use Illuminate\Support\Facades\File;

/**
 * Looks through the project for code that writes to the local disk.
 *
 * Cloud rebuilds the filesystem on every deploy and each replica has its
 * own, so anything the app writes to storage/app is lost. This finds the
 * usual suspects so the report can point at them.
 */
class LocalDiskScanner
{
    protected const PATTERNS = [
        '/Storage::disk\(\s*[\'"](local|public)[\'"]\s*\)/',
        '/->(?:store|storeAs|storePublicly|storePubliclyAs)\([^)]*[\'"]public[\'"]/',
        '/[\'"]disk[\'"]\s*=>\s*[\'"](?:local|public)[\'"]/',
        '/storage_path\(\s*[\'"]app/',
        '/public_path\(\s*[\'"]storage/',
    ];

    protected const DIRECTORIES = ['app', 'routes', 'resources/views', 'database/seeders'];

    /**
     * @return array<int, string> "path:line: code" for each match, at most $limit
     */
    public static function scan(string $projectPath, int $limit = 20): array
    {
        $matches = [];

        foreach (self::DIRECTORIES as $directory) {
            $path = rtrim($projectPath, '/').'/'.$directory;

            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                foreach (preg_split('/\r\n|\n/', $file->getContents()) ?: [] as $number => $line) {
                    foreach (self::PATTERNS as $pattern) {
                        if (preg_match($pattern, $line)) {
                            $matches[] = $directory.'/'.$file->getRelativePathname().':'.($number + 1).': '.trim($line);

                            if (count($matches) >= $limit) {
                                return $matches;
                            }

                            break;
                        }
                    }
                }
            }
        }

        return $matches;
    }
}
