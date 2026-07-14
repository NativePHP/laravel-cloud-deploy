<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use NativePhp\LaravelCloudDeploy\CloudClient;

test('deleteApplication sends a DELETE request for the application', function () {
    Http::fake([
        'app.laravel.cloud/api/applications/app-1' => Http::response(null, 204),
    ]);

    $response = (new CloudClient('test-token'))->deleteApplication('app-1');

    expect($response->status())->toBe(204);

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://app.laravel.cloud/api/applications/app-1';
    });
});

test('waitForDeployment treats stage failures as terminal', function (string $status) {
    Http::fake([
        'app.laravel.cloud/api/deployments/dep-1' => Http::response([
            'data' => ['attributes' => ['status' => $status]],
        ]),
    ]);

    $deployment = (new CloudClient('test-token'))->waitForDeployment('dep-1', timeoutSeconds: 5);

    expect($deployment['data']['attributes']['status'])->toBe($status);
})->with(['build.failed', 'release.failed', 'deployment.failed', 'failed']);

test('waitForDeployment returns on success statuses', function (string $status) {
    Http::fake([
        'app.laravel.cloud/api/deployments/dep-1' => Http::response([
            'data' => ['attributes' => ['status' => $status]],
        ]),
    ]);

    $deployment = (new CloudClient('test-token'))->waitForDeployment('dep-1', timeoutSeconds: 5);

    expect($deployment['data']['attributes']['status'])->toBe($status);
})->with(['deployed', 'deployment.succeeded']);

test('waitForDeployment keeps polling through intermediate stage successes', function () {
    Http::fakeSequence('app.laravel.cloud/api/deployments/dep-1')
        ->push(['data' => ['attributes' => ['status' => 'build.succeeded']]])
        ->push(['data' => ['attributes' => ['status' => 'deployment.succeeded']]]);

    $deployment = (new CloudClient('test-token'))->waitForDeployment('dep-1', timeoutSeconds: 15, pollIntervalSeconds: 0);

    expect($deployment['data']['attributes']['status'])->toBe('deployment.succeeded');

    Http::assertSentCount(2);
});
