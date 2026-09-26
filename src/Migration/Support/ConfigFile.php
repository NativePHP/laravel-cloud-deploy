<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Loads an existing config/cloud.php without evaluating its env() calls.
 *
 * Requiring the file would replace env('LARAVEL_CLOUD_TOKEN') with the
 * token itself, and writing that back out would put the secret in a file
 * people commit. Instead every env( call is rewritten to build an
 * EnvExpression before the code runs, so the merged file keeps them.
 */
class ConfigFile
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        $code = self::captureEnvCalls((string) file_get_contents($path));

        $config = (static fn () => eval('?>'.$code))();

        if (! is_array($config)) {
            throw new \RuntimeException("{$path} doesn't return an array.");
        }

        return $config;
    }

    /**
     * Point env( calls at EnvExpression::capture(.
     */
    public static function captureEnvCalls(string $code): string
    {
        $tokens = token_get_all($code);
        $output = '';
        $previous = null;

        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            $isEnvCall = is_array($token)
                && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && ltrim($token[1], '\\') === 'env'
                && self::nextMeaningful($tokens, $index) === '('
                && ! in_array($previous, ['->', '::', 'function', '?->'], true);

            $output .= $isEnvCall ? '\\'.EnvExpression::class.'::capture' : $text;

            if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = is_array($token) ? ($token[0] === T_FUNCTION ? 'function' : $token[1]) : $token;
            }
        }

        return $output;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    protected static function nextMeaningful(array $tokens, int $index): ?string
    {
        for ($i = $index + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }
}
