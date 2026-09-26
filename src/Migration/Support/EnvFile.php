<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

use Illuminate\Support\Facades\File;

/**
 * Reads and writes dotenv files without evaluating them.
 */
class EnvFile
{
    /**
     * Parse dotenv content into an ordered key => value map.
     *
     * Handles comments, `export` prefixes, single quotes (literal), double
     * quotes (with escapes, may span lines) and inline comments after
     * unquoted values. Variable references like ${APP_NAME} are kept as-is.
     *
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        $values = [];
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }

            [$key, $raw] = [$matches[1], $matches[2]];

            if (str_starts_with($raw, '"')) {
                $value = substr($raw, 1);

                // Keep reading lines until the closing quote.
                while (! self::hasClosingQuote($value) && $i + 1 < $count) {
                    $value .= "\n".$lines[++$i];
                }

                $end = self::closingQuotePosition($value);
                $value = $end === null ? $value : substr($value, 0, $end);
                $values[$key] = stripcslashes(str_replace('\\$', '$', $value));

                continue;
            }

            if (str_starts_with($raw, "'")) {
                $end = strpos($raw, "'", 1);
                $values[$key] = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);

                continue;
            }

            $values[$key] = trim((string) preg_replace('/\s+#.*$/', '', $raw));
        }

        return $values;
    }

    /**
     * Set keys in a dotenv file, replacing existing lines or appending new ones.
     *
     * @param  array<string, string>  $values
     */
    public static function put(string $path, array $values): void
    {
        $content = File::exists($path) ? File::get($path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace_callback($pattern, fn () => $line, $content, 1);
            } else {
                $content = rtrim($content, "\n").($content === '' ? '' : "\n").$line."\n";
            }
        }

        File::put($path, $content);
    }

    /**
     * Quote a value when it contains characters dotenv would misread.
     */
    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@|+-]+$/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, "\"\\\n\$").'"';
    }

    protected static function hasClosingQuote(string $value): bool
    {
        return self::closingQuotePosition($value) !== null;
    }

    protected static function closingQuotePosition(string $value): ?int
    {
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '\\') {
                $i++;

                continue;
            }

            if ($value[$i] === '"') {
                return $i;
            }
        }

        return null;
    }
}
