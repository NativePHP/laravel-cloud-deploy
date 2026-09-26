<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Writes a config array out as a readable PHP config file.
 */
class ConfigRenderer
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function render(array $config, string $header = ''): string
    {
        $php = "<?php\n\n";

        if ($header !== '') {
            $php .= '// '.implode("\n// ", explode("\n", trim($header)))."\n\n";
        }

        return $php.'return '.self::export($config, 0).";\n";
    }

    protected static function export(mixed $value, int $depth): string
    {
        if ($value instanceof EnvExpression) {
            return $value->toPhp();
        }

        if (! is_array($value)) {
            return match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                default => var_export($value, true),
            };
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $closing = str_repeat('    ', $depth);
        $isList = array_is_list($value);
        $lines = [];

        foreach ($value as $key => $item) {
            $prefix = $isList ? '' : var_export($key, true).' => ';
            $lines[] = $indent.$prefix.self::export($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n{$closing}]";
    }
}
