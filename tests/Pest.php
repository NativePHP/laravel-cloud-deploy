<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use NativePhp\LaravelCloudDeploy\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Fake the Cloud API with routes keyed by "METHOD /path" (wildcards allowed).
 *
 * The path is matched without the /api prefix and without the query string.
 * A route's value is a response body array, an Http::response(), or a
 * closure that receives the request. Unmatched requests get an empty list.
 *
 * @param  array<string, array<string, mixed>|PromiseInterface|Closure>  $routes
 */
function fakeCloudApi(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/api');
        $key = $request->method().' '.$path;

        foreach ($routes as $pattern => $response) {
            if (! Str::is($pattern, $key)) {
                continue;
            }

            if ($response instanceof Closure) {
                return $response($request);
            }

            return is_array($response) ? Http::response($response) : $response;
        }

        return Http::response(['data' => []]);
    });
}

/**
 * Whether a recorded request is a write (anything but GET).
 */
function isWrite(Request $request): bool
{
    return $request->method() !== 'GET';
}
